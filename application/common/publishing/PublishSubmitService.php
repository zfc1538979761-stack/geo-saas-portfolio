<?php

declare(strict_types=1);

namespace app\common\publishing;

use app\common\billing\BillingService;
use app\common\supplier\contract\SupplierAdapter;
use app\common\supplier\dto\OrderSubmitRequest;
use app\common\supplier\dto\OrderQuery;
use app\common\supplier\dto\OrderSubmitResult;
use app\common\supplier\dto\SupplierFailure;
use PDO;
use RuntimeException;

final class PublishSubmitService
{
    private PDO $pdo;
    private PublishOrderService $orderService;
    private BillingService $billing;
    private string $prefix;

    public function __construct(PDO $pdo, PublishOrderService $orderService, BillingService $billing, string $prefix)
    {
        $this->pdo = $pdo;
        $this->orderService = $orderService;
        $this->billing = $billing;
        $this->prefix = $prefix;
    }

    /**
     * Submit a queued order to the supplier.
     *
     * @param SupplierAdapter $adapter The supplier adapter instance
     * @param int $companyId
     * @param int $orderId
     * @param string $channelCode Internal channel code (e.g., 'official_media')
     * @param array $externalMediaIds Array of external media IDs
     * @param string $idempotencyKey Unique idempotency key for the supplier request
     * @param string $ip Client IP for audit logging
     * @return array The submit result
     */
    public function submitOrder(
        SupplierAdapter $adapter,
        int $companyId,
        int $orderId,
        string $channelCode,
        array $externalMediaIds,
        string $idempotencyKey,
        string $ip = '',
        array $submitContext = []
    ): array {
        $order = $this->orderService->getDetail($companyId, $orderId);

        // Only queued orders can be submitted
        if ($order['order']['status'] !== 'queued') {
            throw PublishException::invalidStateTransition(
                $order['order']['status'],
                'submitted'
            );
        }

        // Build the request
        $request = new OrderSubmitRequest($idempotencyKey, $channelCode, $externalMediaIds, $submitContext);

        // Submit to supplier
        $result = $adapter->submitOrder($request);

        // Process the result
        return $this->processResult($companyId, $orderId, $order, $result, $ip);
    }

    /**
     * Handle timeout - query the order instead of blindly retrying.
     */
    public function handleTimeout(
        SupplierAdapter $adapter,
        int $companyId,
        int $orderId,
        string $externalOrderNo,
        string $ip = ''
    ): array {
        if ($externalOrderNo === '') {
            // No external order number to query; must mark as manual_review
            $this->markItemsManualReview($companyId, $orderId, 'Submit timeout with no external order number');
            return ['status' => 'manual_review', 'reason' => 'Submit timeout, no query target'];
        }

        $queryContext = $this->orderQueryContext($companyId, $orderId);
        $result = $adapter->queryOrder(new OrderQuery(
            $externalOrderNo,
            '',
            $queryContext['channelCode'],
            $queryContext['catalog']
        ));

        if ($result->supplierStatusKnown()) {
            $order = $this->orderService->getDetail($companyId, $orderId);
            $submitResult = $this->toSubmitResultFromQuery($result);
            return $this->processResult($companyId, $orderId, $order, $submitResult, $ip);
        }

        // Still unknown; mark for manual review
        $this->markItemsManualReview($companyId, $orderId, 'Timeout resolved to unknown status');
        return ['status' => 'manual_review', 'reason' => 'Timeout resolved to unknown status'];
    }

    private function processResult(
        int $companyId,
        int $orderId,
        array $order,
        OrderSubmitResult $result,
        string $ip
    ): array {
        $this->pdo->beginTransaction();
        $pendingTransitions = [];
        try {
            $itemsTable = $this->prefix . 'publish_order_items';

            // Update each item with the submit result
            foreach ($order['items'] as $index => $item) {
                $itemId = (int) $item['id'];
                $itemResult = $result->itemResults()[$index] ?? null;

                $newStatus = 'manual_review';
                $supplierOrderNo = null;
                $supplierRawStatus = null;
                $submitError = null;

                if ($itemResult !== null) {
                    $newStatus = $itemResult->status();
                    $supplierRawStatus = $result->supplierRawStatus() ?? $newStatus;

                    if ($itemResult->failure() !== null) {
                        $submitError = $itemResult->failure()->redactedReason();
                    }
                }
                // Supplier queued is a provisional result; local state advances to submitted.
                $finalStatus = $newStatus === 'queued' ? 'submitted' : $newStatus;
                $pendingTransitions[] = [
                    'itemId' => $itemId,
                    'status' => $finalStatus,
                    'note' => $submitError ?? 'Submitted to supplier: ' . ($result->externalOrderNo() ?? 'no ext order no'),
                ];

                $upd = $this->pdo->prepare(
                    'UPDATE ' . $this->q($itemsTable)
                    . ' SET supplier_order_no = COALESCE(:sono, supplier_order_no),'
                    . ' supplier_raw_status = :srs, submit_error = :serr, submitted_at = NOW(),'
                    . ' supplier_submit_request_id = :srid, updated_at = NOW()'
                    . ' WHERE id = :id AND company_id = :cid'
                );
                $upd->execute([
                    'sono' => $result->externalOrderNo(),
                    'srs' => $supplierRawStatus,
                    'serr' => $submitError,
                    'srid' => $result->supplierRequestId() ?? $result->externalOrderNo(),
                    'id' => $itemId,
                    'cid' => $companyId,
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        foreach ($pendingTransitions as $transition) {
            $this->orderService->transitionItem(
                $companyId,
                $orderId,
                $transition['itemId'],
                $transition['status'],
                'supplier',
                $transition['note'],
                $ip
            );
        }

        return [
            'status' => $result->status(),
            'external_order_no' => $result->externalOrderNo(),
            'item_count' => count($result->itemResults()),
            'successful' => count($result->successfulItemIds()),
            'failed' => count($result->failedItemIds()),
        ];
    }

    private function toSubmitResultFromQuery(\app\common\supplier\dto\OrderResult $queryResult): OrderSubmitResult
    {
        // Convert OrderResult (from query) into an OrderSubmitResult for unified processing
        $itemResults = [];
        $rawPayload = $queryResult->rawPayload();
        $items = $rawPayload !== null ? ($rawPayload->payload()['items'] ?? []) : [];

        foreach ($items as $item) {
            $itemStatus = $item['status'] ?? 'manual_review';
            $itemResults[] = new \app\common\supplier\dto\OrderSubmitItemResult(
                $item['mediaId'] ?? ($item['popId'] ?? 'unknown'),
                $itemStatus,
                null
            );
        }

        $supplierRequestId = $rawPayload !== null
            ? trim((string) ($rawPayload->payload()['supplierRequestId'] ?? ''))
            : '';

        return new OrderSubmitResult(
            '',
            $queryResult->externalOrderNo(),
            $queryResult->status(),
            $queryResult->supplierRawStatus(),
            $queryResult->publishedUrl(),
            null,
            $itemResults,
            $supplierRequestId !== '' ? $supplierRequestId : null
        );
    }

    private function markItemsManualReview(int $companyId, int $orderId, string $reason): void
    {
        $order = $this->orderService->getDetail($companyId, $orderId);
        foreach ($order['items'] as $item) {
            if ($item['status'] !== 'manual_review') {
                $this->orderService->transitionItem(
                    $companyId,
                    $orderId,
                    (int) $item['id'],
                    'manual_review',
                    'system',
                    $reason,
                    ''
                );
            }
        }
    }

    private function orderQueryContext(int $companyId, int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.`code` AS channel_code, m.`attributes_json`'
            . ' FROM ' . $this->q($this->prefix . 'publish_order_items') . ' i'
            . ' LEFT JOIN ' . $this->q($this->prefix . 'media_resources') . ' m ON m.`id` = i.`media_id`'
            . ' LEFT JOIN ' . $this->q($this->prefix . 'channels') . ' c ON c.`id` = m.`channel_id`'
            . ' WHERE i.`company_id` = :cid AND i.`order_id` = :oid LIMIT 1'
        );
        $stmt->execute(['cid' => $companyId, 'oid' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['channelCode' => '', 'catalog' => ''];
        }
        $attributes = json_decode((string) ($row['attributes_json'] ?? '{}'), true);
        $catalog = is_array($attributes) ? trim((string) ($attributes['source_catalog'] ?? '')) : '';
        return [
            'channelCode' => trim((string) ($row['channel_code'] ?? '')),
            'catalog' => $catalog,
        ];
    }

    private function q(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('SQL identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
}

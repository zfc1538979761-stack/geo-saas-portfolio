<?php

declare(strict_types=1);

namespace app\common\publishing;

use app\common\supplier\contract\SupplierAdapter;
use app\common\supplier\dto\CallbackEnvelope;
use app\common\supplier\dto\SupplierRawPayload;
use app\common\supplier\exception\SupplierDuplicateCallbackException;
use PDO;
use RuntimeException;
use Throwable;

final class PublishCallbackService
{
    private PDO $pdo;
    private PublishSyncService $syncService;
    private PublishOrderService $orderService;
    private string $prefix;
    private string $webhookTable;
    private string $itemsTable;

    public function __construct(
        PDO $pdo,
        PublishSyncService $syncService,
        PublishOrderService $orderService,
        string $prefix
    ) {
        $this->pdo = $pdo;
        $this->syncService = $syncService;
        $this->orderService = $orderService;
        $this->prefix = $prefix;
        $this->webhookTable = $prefix . 'webhook_events';
        $this->itemsTable = $prefix . 'publish_order_items';
    }

    /**
     * Process an incoming webhook/callback event from a supplier.
     *
     * @param SupplierAdapter $adapter    The supplier adapter instance
     * @param int             $companyId  Tenant company ID (from route or parsing)
     * @param string          $eventId    Unique event ID from the supplier
     * @param string          $body       Raw HTTP request body (JSON string)
     * @return array                      Processing result
     */
    public function processCallback(
        SupplierAdapter $adapter,
        int $companyId,
        string $eventId,
        string $body
    ): array {
        $supplierCode = $adapter->supplierCode();
        $rawPayload = new SupplierRawPayload($this->safeJsonDecode($body));

        // Phase 1: Persist raw event (dedup via unique key)
        try {
            $ins = $this->pdo->prepare(
                'INSERT INTO ' . $this->q($this->webhookTable)
                . ' (company_id, supplier_code, event_id, status, raw_payload_json, received_at)'
                . ' VALUES (:cid, :sc, :eid, :st, :rp, NOW())'
            );
            $ins->execute([
                'cid' => $companyId,
                'sc' => $supplierCode,
                'eid' => $eventId,
                'st' => 'received',
                'rp' => $body,
            ]);
            $webhookId = (int) $this->pdo->lastInsertId();
        } catch (\PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                // Event already seen — load existing record
                $existing = $this->loadEvent($supplierCode, $eventId);
                return [
                    'status' => 'duplicate',
                    'webhook_id' => (int) $existing['id'],
                    'original_result' => $existing['processing_result'],
                    'message' => 'Event already processed.',
                ];
            }
            throw $e;
        }

        // Phase 2: Parse callback via adapter (includes signature verification)
        try {
            $envelope = new CallbackEnvelope($supplierCode, $eventId, $rawPayload);
            $parsed = $adapter->parseCallback($envelope);
        } catch (SupplierDuplicateCallbackException $e) {
            // Adapter-level duplicate detection
            $this->updateEventResult($webhookId, 'duplicate', null, $e->getMessage());
            return [
                'status' => 'duplicate',
                'webhook_id' => $webhookId,
                'message' => 'Supplier reported duplicate: ' . $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            // Parse failure (e.g., invalid signature)
            $this->updateEventResult($webhookId, 'parse_failed', null, $e->getMessage());
            return [
                'status' => 'parse_failed',
                'webhook_id' => $webhookId,
                'message' => 'Callback parse failed: ' . $e->getMessage(),
            ];
        }

        // Phase 3: Resolve external order to local order
        $externalOrderNo = $parsed->externalOrderNo();
        $localOrderId = $this->resolveLocalOrderId($companyId, $externalOrderNo);
        if ($localOrderId === null) {
            $this->updateEventResult($webhookId, 'unmatched_order', null,
                'No local order found for external: ' . $externalOrderNo);
            return [
                'status' => 'unmatched_order',
                'webhook_id' => $webhookId,
                'message' => 'No local order matches external order ' . $externalOrderNo,
            ];
        }

        // Phase 4: Process status change via existing PublishSyncService
        // This reuses polling logic — same state transitions, same fund operations
        try {
            $syncResult = $this->syncService->syncOrder(
                $adapter,
                $companyId,
                $localOrderId,
                '' // ip not available from callback
            );

            $this->updateEventResult(
                $webhookId,
                'processed',
                $syncResult['status'],
                $syncResult['message'] ?? null
            );

            return [
                'status' => 'processed',
                'webhook_id' => $webhookId,
                'sync_result' => $syncResult,
            ];
        } catch (\Throwable $e) {
            $this->updateEventResult($webhookId, 'processing_error', null, $e->getMessage());
            return [
                'status' => 'processing_error',
                'webhook_id' => $webhookId,
                'message' => 'Status processing failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Replay a persisted callback without bypassing the order state machine.
     *
     * Processed and duplicate events return a safe idempotent no-op. Failed,
     * unmatched, parse-failed, and received events are reset and processed
     * again through PublishSyncService.
     */
    public function replayEvent(int $webhookId, SupplierAdapter $adapter): array
    {
        $event = $this->loadEventById($webhookId);
        $eventId = (string) $event['event_id'];
        if (!in_array((string) $event['status'], ['received', 'parse_failed', 'unmatched_order', 'processing_error'], true)) {
            return [
                'status' => 'duplicate',
                'webhook_id' => (int) $event['id'],
                'original_result' => $event['processing_result'],
                'message' => 'Event already processed; replay is idempotent.',
            ];
        }

        $this->resetEventForReplay((int) $event['id']);
        $companyId = (int) $event['company_id'];
        $rawPayload = new SupplierRawPayload($this->safeJsonDecode((string) $event['raw_payload_json']));
        try {
            $envelope = new CallbackEnvelope((string) $event['supplier_code'], $eventId, $rawPayload);
            $parsed = $adapter->parseCallback($envelope);
        } catch (SupplierDuplicateCallbackException $exception) {
            $this->updateEventResult((int) $event['id'], 'duplicate', null, $exception->getMessage());
            return [
                'status' => 'duplicate',
                'webhook_id' => (int) $event['id'],
                'message' => 'Supplier reported duplicate: ' . $exception->getMessage(),
            ];
        } catch (Throwable $exception) {
            $this->updateEventResult((int) $event['id'], 'parse_failed', null, $exception->getMessage());
            return [
                'status' => 'parse_failed',
                'webhook_id' => (int) $event['id'],
                'message' => 'Callback replay parse failed: ' . $exception->getMessage(),
            ];
        }

        $externalOrderNo = $parsed->externalOrderNo();
        $localOrderId = $this->resolveLocalOrderId($companyId, $externalOrderNo);
        if ($localOrderId === null) {
            $this->updateEventResult((int) $event['id'], 'unmatched_order', null, 'No local order found for external: ' . $externalOrderNo);
            return [
                'status' => 'unmatched_order',
                'webhook_id' => (int) $event['id'],
                'message' => 'No local order matches external order ' . $externalOrderNo,
            ];
        }

        try {
            $syncResult = $this->syncService->syncOrder($adapter, $companyId, $localOrderId, '');
            $this->updateEventResult((int) $event['id'], 'processed', $syncResult['status'], $syncResult['message'] ?? null);
            return [
                'status' => 'processed',
                'webhook_id' => (int) $event['id'],
                'sync_result' => $syncResult,
            ];
        } catch (Throwable $exception) {
            $this->updateEventResult((int) $event['id'], 'processing_error', null, $exception->getMessage());
            return [
                'status' => 'processing_error',
                'webhook_id' => (int) $event['id'],
                'message' => 'Status processing failed: ' . $exception->getMessage(),
            ];
        }
    }

    /**
     * Find the local order ID by external order number from publish_order_items.
     */
    private function resolveLocalOrderId(int $companyId, string $externalOrderNo): ?int
    {
        if ($externalOrderNo === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT order_id FROM ' . $this->q($this->itemsTable)
            . ' WHERE company_id = :cid AND supplier_submit_request_id = :eno'
            . ' ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['cid' => $companyId, 'eno' => $externalOrderNo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int) $row['order_id'] : null;
    }

    private function loadEvent(string $supplierCode, string $eventId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->q($this->webhookTable)
            . ' WHERE supplier_code = :sc AND event_id = :eid LIMIT 1'
        );
        $stmt->execute(['sc' => $supplierCode, 'eid' => $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Webhook event not found: ' . $eventId);
        }
        return $row;
    }

    private function loadEventById(int $webhookId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->q($this->webhookTable) . ' WHERE `id`=:id LIMIT 1'
        );
        $stmt->execute(['id' => $webhookId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Webhook event not found: ' . $webhookId);
        }
        return $row;
    }

    private function resetEventForReplay(int $webhookId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->q($this->webhookTable)
            . " SET `status`='received',`processing_result`=NULL,`processing_error`=NULL,`processed_at`=NULL"
            . ' WHERE `id`=:id'
        );
        $stmt->execute(['id' => $webhookId]);
    }

    private function updateEventResult(
        int $webhookId,
        string $status,
        ?string $processingResult,
        ?string $error
    ): void {
        $upd = $this->pdo->prepare(
            'UPDATE ' . $this->q($this->webhookTable)
            . ' SET status = :st, processing_result = :pr, processing_error = :pe,'
            . ' processed_at = NOW() WHERE id = :id'
        );
        $upd->execute([
            'st' => $status,
            'pr' => $processingResult,
            'pe' => $error !== null ? mb_substr($error, 0, 500) : null,
            'id' => $webhookId,
        ]);
    }

    private function safeJsonDecode(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Callback body is not valid JSON.');
        }
        return $decoded;
    }

    private function isDuplicateKey(\PDOException $e): bool
    {
        $code = $e->getCode();
        if (is_string($code) && $code === '23000') {
            return true;
        }
        return stripos($e->getMessage(), 'duplicate') !== false
            || stripos($e->getMessage(), '1062') !== false;
    }

    private function q(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('SQL identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
}

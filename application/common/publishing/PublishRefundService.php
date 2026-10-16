<?php

declare(strict_types=1);

namespace app\common\publishing;

use app\common\billing\BillingService;
use PDO;
use RuntimeException;

final class PublishRefundService
{
    private PDO $pdo;
    private PublishOrderService $orderService;
    private BillingService $billing;
    private string $prefix;
    private string $refundsTable;
    private string $itemsTable;
    private string $ordersTable;

    /**
     * Factory: create instance using ThinkPHP config (for API controllers).
     */
    public static function create(): self
    {
        $prefix = \think\Config::get("database.prefix") ?: "fa_";
        return new self(
            \think\Db::connect()->getPdo(),
            PublishOrderFactory::create(),
            \app\common\billing\BillingServiceFactory::create(),
            $prefix
        );
    }

    public function __construct(
        PDO $pdo,
        PublishOrderService $orderService,
        BillingService $billing,
        string $prefix
    ) {
        $this->pdo = $pdo;
        $this->orderService = $orderService;
        $this->billing = $billing;
        $this->prefix = $prefix;
        $this->refundsTable = $prefix . 'publish_refund_requests';
        $this->itemsTable = $prefix . 'publish_order_items';
        $this->ordersTable = $prefix . 'publish_orders';
    }

    /**
     * Request a refund for all published items in an order.
     * MVP: auto-approves and executes the refund immediately.
     *
     * @return array { refund_request, order }
     */
    public function requestRefund(
        int $companyId,
        int $orderId,
        int $userId,
        string $reason,
        string $ip = ''
    ): array {
        // Load order and validate
        $order = $this->orderService->getDetail($companyId, $orderId, false);
        $orderStatus = $order['order']['status'];

        // Only orders with published items can be refunded
        $publishableItems = $this->filterPublishedItems($order['items']);
        if ($publishableItems === []) {
            if ($orderStatus === 'refunded') {
                $existing = $this->findActiveRefund($companyId, $orderId);
                if ($existing !== null) {
                    return $this->buildResponse($existing, $order);
                }
            }
            throw PublishException::invalidStateTransition(
                $orderStatus,
                'after_sale'
            );
        }

        // Idempotency: check for existing active refund
        $existing = $this->findActiveRefund($companyId, $orderId);
        if ($existing !== null) {
            return $this->buildResponse($existing, $order);
        }

        $totalAmount = '0.00';
        foreach ($publishableItems as $item) {
            $totalAmount = bcadd($totalAmount, $item['sale_snapshot'], 2);
        }

        $requestNo = 'RF' . date('Ymd') . strtoupper(substr(
            hash('sha256', uniqid((string) $companyId, true)),
            0,
            10
        ));

        // Phase 1: Create refund request (single INSERT, atomic in InnoDB)
        $insRequest = $this->pdo->prepare(
            'INSERT INTO ' . $this->q($this->refundsTable)
            . ' (company_id, order_id, request_no, status, total_amount, reason,'
            . ' requested_by, created_at, updated_at)'
            . ' VALUES (:cid, :oid, :rno, :st, :ta, :rsn, :rby, NOW(), NOW())'
        );
        $insRequest->execute([
            'cid' => $companyId,
            'oid' => $orderId,
            'rno' => $requestNo,
            'st' => 'pending_review',
            'ta' => $totalAmount,
            'rsn' => $reason !== '' ? $reason : null,
            'rby' => $userId,
        ]);
        $refundId = (int) $this->pdo->lastInsertId();

        // Phase 2: Transition each published item: published -> after_sale
        // (Each transitionItem handles its own transaction)
        try {
            foreach ($publishableItems as $item) {
                $itemId = (int) $item['id'];
                $this->orderService->transitionItem(
                    $companyId,
                    $orderId,
                    $itemId,
                    'after_sale',
                    'enterprise',
                    'Refund requested by user ' . $userId . ': ' . ($reason !== '' ? $reason : 'no reason'),
                    $ip
                );
            }
        } catch (\Throwable $e) {
            // Rollback: delete the refund request
            $this->pdo->exec('DELETE FROM ' . $this->q($this->refundsTable) . ' WHERE id = ' . $refundId);
            throw $e;
        }

        // Phase 3: Auto-approve (MVP: immediate approval)
        // Transition each after_sale item: after_sale -> refunded (calls BillingService::refund)
        try {
            foreach ($publishableItems as $item) {
                $itemId = (int) $item['id'];
                $this->orderService->transitionItem(
                    $companyId,
                    $orderId,
                    $itemId,
                    'refunded',
                    'system',
                    'Auto-approved refund for request ' . $requestNo,
                    $ip
                );
            }

            // Update refund request status to approved
            $updRefund = $this->pdo->prepare(
                'UPDATE ' . $this->q($this->refundsTable)
                . ' SET status = :st, review_note = :rn, reviewed_at = NOW(), updated_at = NOW()'
                . ' WHERE id = :id'
            );
            $updRefund->execute([
                'st' => 'approved',
                'rn' => 'MVP auto-approved',
                'id' => $refundId,
            ]);
        } catch (\Throwable $e) {
            // Mark refund request as failed
            $updFail = $this->pdo->prepare(
                'UPDATE ' . $this->q($this->refundsTable)
                . ' SET status = :st, review_note = :rn, updated_at = NOW()'
                . ' WHERE id = :id'
            );
            $updFail->execute([
                'st' => 'pending_review',
                'rn' => 'Auto-approval failed: ' . $e->getMessage(),
                'id' => $refundId,
            ]);
            throw $e;
        }

        // Reload order
        $updatedOrder = $this->orderService->getDetail($companyId, $orderId, false);

        // Notification
        try {
            $notifService = new PublishNotificationService($this->pdo, $this->prefix);
            $notifService->createNotification(
                $companyId,
                $orderId,
                null,
                'refunded',
                'Refund completed: ' . $requestNo,
                [
                    'order_id' => $orderId,
                    'refund_request_no' => $requestNo,
                    'total_amount' => $totalAmount,
                ]
            );
        } catch (\Throwable $e) {
            // Non-fatal: notification failure does not rollback refund
        }

        return $this->buildResponse($this->loadRefund($refundId), $updatedOrder);
    }

    /**
     * Get refund requests for an order.
     */
    public function listRefunds(int $companyId, int $orderId, int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $cs = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . $this->q($this->refundsTable)
            . ' WHERE company_id = :cid AND order_id = :oid'
        );
        $cs->execute(['cid' => $companyId, 'oid' => $orderId]);
        $total = (int) $cs->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->q($this->refundsTable)
            . ' WHERE company_id = :cid AND order_id = :oid'
            . ' ORDER BY id DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue('cid', $companyId, PDO::PARAM_INT);
        $stmt->bindValue('oid', $orderId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = $this->formatRefundRow($row);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Get a single refund request.
     */
    public function getRefund(int $companyId, int $refundId): array
    {
        return $this->formatRefundRow($this->loadRefundById($companyId, $refundId));
    }

    private function loadRefund(int $refundId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->q($this->refundsTable) . ' WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $refundId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Refund request not found: ' . $refundId);
        }
        return $row;
    }

    private function loadRefundById(int $companyId, int $refundId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->q($this->refundsTable)
            . ' WHERE id = :id AND company_id = :cid LIMIT 1'
        );
        $stmt->execute(['id' => $refundId, 'cid' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw PublishException::orderNotFound($companyId, $refundId);
        }
        return $row;
    }

    private function findActiveRefund(int $companyId, int $orderId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->q($this->refundsTable)
            . ' WHERE company_id = :cid AND order_id = :oid'
            . " AND status IN ('pending_review', 'approved')"
            . ' ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['cid' => $companyId, 'oid' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function filterPublishedItems(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if ($item['status'] === 'published') {
                $result[] = $item;
            }
        }
        return $result;
    }

    private function buildResponse(array $refund, array $order): array
    {
        return [
            'refund_request' => $this->formatRefundRow($refund),
            'order' => $order['order'],
        ];
    }

    private function formatRefundRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'company_id' => (int) $row['company_id'],
            'order_id' => (int) $row['order_id'],
            'request_no' => $row['request_no'],
            'status' => $row['status'],
            'total_amount' => PublishOrderService::fmt($row['total_amount']),
            'reason' => $row['reason'],
            'review_note' => $row['review_note'],
            'requested_by' => (int) $row['requested_by'],
            'reviewed_at' => $row['reviewed_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
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

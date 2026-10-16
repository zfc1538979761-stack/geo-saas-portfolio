<?php

declare(strict_types=1);

namespace app\common\publishing;

use PDO;
use app\common\billing\BillingService;

final class PublishOrderService
{
    private PDO $pdo;
    private BillingService $billing;
    private OrderStateMachine $stateMachine;
    private string $prefix;
    private string $ordersTable;
    private string $itemsTable;
    private string $logsTable;
    private string $mediaTable;

    public function __construct(PDO $pdo, BillingService $billing, string $prefix)
    {
        $this->pdo = $pdo;
        $this->billing = $billing;
        $this->stateMachine = new OrderStateMachine();
        $this->prefix = $prefix;
        $this->ordersTable = $prefix . 'publish_orders';
        $this->itemsTable = $prefix . 'publish_order_items';
        $this->logsTable = $prefix . 'order_status_logs';
        $this->mediaTable = $prefix . 'media_resources';
    }

    public function preview(int $companyId, int $articleId, int $articleVersionId, array $mediaIds): array
    {
        $this->verifyArticleOwnership($companyId, $articleId, $articleVersionId);
        $mediaMap = $this->loadActiveMedia(array_unique($mediaIds));
        $items = [];
        $totalSale = '0.00';
        $sortedMediaIds = $mediaIds;
        sort($sortedMediaIds, SORT_NUMERIC);
        foreach ($sortedMediaIds as $mediaId) {
            if (!isset($mediaMap[$mediaId])) { throw PublishException::mediaNotAvailable($mediaId); }
            $m = $mediaMap[$mediaId];
            $salePrice = $this->calcSalePrice($m['cost_price']);
            $items[] = ['media_id' => $mediaId, 'media_name' => $m['name'], 'channel_id' => (int) $m['channel_id'], 'supplier_id' => (int) $m['supplier_id'], 'sale_price' => $salePrice];
            $totalSale = bcadd($totalSale, $salePrice, 2);
        }
        $fingerprint = $this->buildPriceFingerprint($companyId, $articleVersionId, $sortedMediaIds, $mediaMap);
        return ['company_id' => $companyId, 'article_id' => $articleId, 'article_version_id' => $articleVersionId, 'items' => $items, 'total_amount' => $totalSale, 'price_fingerprint' => $fingerprint];
    }

    public function create(
        int $companyId,
        int $userId,
        int $articleId,
        int $articleVersionId,
        array $mediaIds,
        string $idempotencyKey,
        string $priceFingerprint,
        string $ip = '',
        string $originalLink = ''
    ): array
    {
        if (count($mediaIds) === 0) { throw PublishException::emptyMediaList(); }
        sort($mediaIds, SORT_NUMERIC);
        $existing = $this->findIdempotentOrder($companyId, $idempotencyKey);
        if ($existing !== null) { return $existing; }
        $originalLink = trim($originalLink);
        $this->verifyArticleOwnership($companyId, $articleId, $articleVersionId);
        $mediaMap = $this->loadActiveMedia($mediaIds);
        foreach ($mediaIds as $mediaId) { if (!isset($mediaMap[$mediaId])) { throw PublishException::mediaNotAvailable($mediaId); } }
        $this->validateMaterialLink($mediaMap, $originalLink);
        $currentFingerprint = $this->buildPriceFingerprint($companyId, $articleVersionId, $mediaIds, $mediaMap);
        if ($currentFingerprint !== $priceFingerprint) { throw PublishException::priceChanged($currentFingerprint, $priceFingerprint); }
        $totalSale = '0.00';
        foreach ($mediaIds as $mediaId) { $totalSale = bcadd($totalSale, $this->calcSalePrice($mediaMap[$mediaId]['cost_price']), 2); }
        $orderNo = 'PO' . date('Ymd') . strtoupper(substr(hash('sha256', uniqid((string) $companyId, true)), 0, 10));
        $requestFingerprint = hash('sha256', implode('|', [(string) $companyId, (string) $articleVersionId, implode(',', $mediaIds), $currentFingerprint]));
        $this->pdo->beginTransaction();
        try {
            $insOrder = $this->pdo->prepare('INSERT INTO ' . $this->q($this->ordersTable) . ' (order_no, company_id, article_id, article_version_id, status, total_amount, frozen_amount, idempotency_key, request_fingerprint, original_link, created_by, created_at, updated_at) VALUES (:ono, :cid, :aid, :avid, :st, :ta, :fa, :ik, :rf, :ol, :cby, NOW(), NOW())');
            $insOrder->execute(['ono' => $orderNo, 'cid' => $companyId, 'aid' => $articleId, 'avid' => $articleVersionId, 'st' => 'funds_frozen', 'ta' => $totalSale, 'fa' => $totalSale, 'ik' => $idempotencyKey, 'rf' => $requestFingerprint, 'ol' => $originalLink !== '' ? $originalLink : null, 'cby' => $userId]);
            $orderId = (int) $this->pdo->lastInsertId();
            $insItem = $this->pdo->prepare('INSERT INTO ' . $this->q($this->itemsTable) . ' (company_id, order_id, media_id, supplier_id, channel_id, cost_snapshot, sale_snapshot, status, created_at, updated_at) VALUES (:cid, :oid, :mid, :sid, :chid, :cost, :sale, :st, NOW(), NOW())');
            $itemIds = [];
            foreach ($mediaIds as $mediaId) {
                $m = $mediaMap[$mediaId];
                $salePrice = $this->calcSalePrice($m['cost_price']);
                $insItem->execute(['cid' => $companyId, 'oid' => $orderId, 'mid' => $mediaId, 'sid' => $m['supplier_id'], 'chid' => $m['channel_id'], 'cost' => $m['cost_price'], 'sale' => $salePrice, 'st' => 'funds_frozen']);
                $itemIds[] = (int) $this->pdo->lastInsertId();
            }
            $this->writeStatusLog($companyId, $orderId, null, null, 'funds_frozen', 'system', 'order:' . $orderId . ':created', 'Order created');
            foreach ($itemIds as $idx => $itemId) { $mediaId = $mediaIds[$idx]; $this->writeStatusLog($companyId, $orderId, $itemId, null, 'funds_frozen', 'system', 'order:' . $orderId . ':item:' . $itemId . ':created', 'Item created for media ' . $mediaId); }
            $this->pdo->commit();
        } catch (\PDOException $e) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } if ($e->getCode() === "23000" && stripos($e->getMessage(), "Duplicate") !== false) { $existing = $this->findIdempotentOrder($companyId, $idempotencyKey); if ($existing !== null) { return $existing; } } throw $e; } catch (\Throwable $e) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $e; }
        try { $this->billing->freeze($companyId, 'publish_order', (string) $orderId, $totalSale, 'order:' . $orderId . ':freeze', $ip); }
        catch (\Throwable $e) { $this->updateOrderStatus($companyId, $orderId, 'manual_review', 'order:' . $orderId . ':freeze_failed', 'Freeze failed: ' . $e->getMessage()); throw $e; }
        return $this->loadOrder($companyId, $orderId);
    }

    public function listOrders(int $companyId, int $page, int $perPage, ?string $status = null): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $where = 'WHERE company_id=:cid'; $params = ['cid' => $companyId];
        if ($status !== null && $status !== '') { $where .= ' AND status=:st'; $params['st'] = $status; }
        $cs = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $this->q($this->ordersTable) . ' ' . $where);
        $cs->execute($params); $total = (int) $cs->fetchColumn();
        $params['lim'] = $perPage; $params['off'] = $offset;
        $ls = $this->pdo->prepare('SELECT * FROM ' . $this->q($this->ordersTable) . ' ' . $where . ' ORDER BY id DESC LIMIT :lim OFFSET :off');
        $ls->bindValue('cid', $companyId, PDO::PARAM_INT);
        if ($status !== null && $status !== '') { $ls->bindValue('st', $status); }
        $ls->bindValue('lim', $perPage, PDO::PARAM_INT); $ls->bindValue('off', $offset, PDO::PARAM_INT);
        $ls->execute();
        $items = [];
        foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $row) { $items[] = $this->formatOrderRow($row); }
        $mediaItemsByOrder = $this->loadOrderMediaItems(
            $companyId,
            array_map(static fn(array $order): int => (int) $order['id'], $items)
        );
        foreach ($items as &$order) {
            $order['media_items'] = $mediaItemsByOrder[(int) $order['id']] ?? [];
        }
        unset($order);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Get full order detail including notifications (T14).
     */
    public function getDetail(int $companyId, int|string $orderId, bool $includeNotifications = true): array
    {
        $orderId = (int) $orderId;
        $order = $this->loadOrder($companyId, $orderId);
        $is = $this->pdo->prepare(
            'SELECT i.*, m.name AS media_name FROM ' . $this->q($this->itemsTable) . ' i'
            . ' LEFT JOIN ' . $this->q($this->mediaTable) . ' m ON m.id=i.media_id'
            . ' WHERE i.company_id=:cid AND i.order_id=:oid ORDER BY i.id ASC'
        );
        $is->execute(['cid' => $companyId, 'oid' => $orderId]);
        $items = []; foreach ($is->fetchAll(PDO::FETCH_ASSOC) as $row) { $items[] = $this->formatItemRow($row); }
        $ls = $this->pdo->prepare('SELECT * FROM ' . $this->q($this->logsTable) . ' WHERE company_id=:cid AND order_id=:oid ORDER BY id ASC');
        $ls->execute(['cid' => $companyId, 'oid' => $orderId]);
        $logs = []; foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $row) { $logs[] = ['id' => (int) $row['id'], 'order_item_id' => $row['order_item_id'] !== null ? (int) $row['order_item_id'] : null, 'from_status' => $row['from_status'], 'to_status' => $row['to_status'], 'source' => $row['source'], 'reason' => $row['reason'], 'created_at' => $row['created_at']]; }

        $result = ['order' => $order, 'items' => $items, 'status_logs' => $logs];

        // T14: Include notifications and unread count
        if ($includeNotifications) {
            $notifService = new PublishNotificationService($this->pdo, $this->prefix);
            $result['notifications'] = $notifService->listNotifications($companyId, 1, 50)['items'];
            $result['unread_count'] = $notifService->countUnread($companyId, $orderId);
        }

        return $result;
    }

    public function cancel(int $companyId, int|string $orderId, int $userId, string $ip = ''): array
    {
        $orderId = (int) $orderId;
        $order = $this->loadOrder($companyId, $orderId);
        if ($order['status'] === 'cancelled') { return $order; }
        $is = $this->pdo->prepare('SELECT * FROM ' . $this->q($this->itemsTable) . ' WHERE company_id=:cid AND order_id=:oid ORDER BY id ASC');
        $is->execute(['cid' => $companyId, 'oid' => $orderId]);
        $items = $is->fetchAll(PDO::FETCH_ASSOC);
        $itemStatuses = array_map(fn($i) => $i['status'], $items);
        if (!OrderStateMachine::canCancelOrder($itemStatuses)) { throw PublishException::cancelNotAllowed($order['status']); }
        $this->pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $upd = $this->pdo->prepare('UPDATE ' . $this->q($this->itemsTable) . ' SET status=:st, updated_at=NOW() WHERE id=:id');
                $upd->execute(['st' => 'cancelled', 'id' => $item['id']]);
            }
            $updOrder = $this->pdo->prepare('UPDATE ' . $this->q($this->ordersTable) . ' SET status=:st, frozen_amount=0.00, updated_at=NOW() WHERE id=:id');
            $updOrder->execute(['st' => 'cancelled', 'id' => $orderId]);
            $this->writeStatusLog($companyId, $orderId, null, $order['status'], 'cancelled', 'enterprise', 'order:' . $orderId . ':cancelled', 'Cancelled by user ' . $userId);
            foreach ($items as $item) { $this->writeStatusLog($companyId, $orderId, (int) $item['id'], $item['status'], 'cancelled', 'enterprise', 'order:' . $orderId . ':item:' . $item['id'] . ':cancelled', 'Cancelled by user ' . $userId); }
            $this->pdo->commit();
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $e; }
        $frozenAmount = $order['frozen_amount'] ?? '0.00';
        if (bccomp($frozenAmount, '0.00', 2) > 0) { $this->billing->release($companyId, 'publish_order', (string) $orderId, $frozenAmount, 'order:' . $orderId . ':item:0:cancel_release', $ip); }
        return $this->loadOrder($companyId, $orderId);
    }

    public function transitionItem(int $companyId, int|string $orderId, int|string $itemId, string $toStatus, string $source = 'system', ?string $reason = null, string $ip = ''): array
    {
        $orderId = (int) $orderId; $itemId = (int) $itemId;
        $order = $this->loadOrder($companyId, $orderId);
        $item = $this->loadItem($companyId, $orderId, $itemId);
        $fromStatus = $item['status'];
        if ($fromStatus === $toStatus) { return $item; }
        $this->stateMachine->validateTransition($fromStatus, $toStatus);
        $eventKey = "order:{$orderId}:item:{$itemId}:{$toStatus}";
        $fundsOp = OrderStateMachine::getFundsOperation($toStatus);
        $this->pdo->beginTransaction();
        try {
            $upd = $this->pdo->prepare('UPDATE ' . $this->q($this->itemsTable) . ' SET status=:st, updated_at=NOW() WHERE id=:id');
            $upd->execute(['st' => $toStatus, 'id' => $itemId]);
            $this->writeStatusLog($companyId, $orderId, $itemId, $fromStatus, $toStatus, $source, $eventKey, $reason);
            $this->recomputeOrderStatus($companyId, $orderId);
            $this->pdo->commit();
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $e; }
        if ($fundsOp !== null) {
            $saleSnapshot = $item['sale_snapshot'];
            $fundsEventKey = "order:{$orderId}:item:{$itemId}:{$fundsOp}";
            if ($fundsOp === 'capture') { $this->billing->capture($companyId, 'publish_order', (string) $orderId, $saleSnapshot, $fundsEventKey, $ip); $this->reduceFrozenAmount($orderId, $saleSnapshot); }
            elseif ($fundsOp === 'release') { $this->billing->release($companyId, 'publish_order', (string) $orderId, $saleSnapshot, $fundsEventKey, $ip); $this->reduceFrozenAmount($orderId, $saleSnapshot); }
            elseif ($fundsOp === 'refund') { $this->billing->refund($companyId, 'publish_order', (string) $orderId, $saleSnapshot, $fundsEventKey, $ip); }
        }
        return $this->loadItem($companyId, $orderId, $itemId);
    }

    private function reduceFrozenAmount(int $orderId, string $amount): void
    {
        $upd = $this->pdo->prepare('UPDATE ' . $this->q($this->ordersTable) . ' SET frozen_amount = GREATEST(0, frozen_amount - :amt), updated_at = NOW() WHERE id = :id');
        $upd->execute(['amt' => $amount, 'id' => $orderId]);
    }

        private function recomputeOrderStatus(int $companyId, int $orderId): void
    {
        $is = $this->pdo->prepare('SELECT status FROM ' . $this->q($this->itemsTable) . ' WHERE company_id=:cid AND order_id=:oid');
        $is->execute(['cid' => $companyId, 'oid' => $orderId]);
        $itemStatuses = array_column($is->fetchAll(PDO::FETCH_ASSOC), 'status');
        $newStatus = OrderStateMachine::deriveOrderStatus($itemStatuses);
        $os = $this->pdo->prepare('SELECT status FROM ' . $this->q($this->ordersTable) . ' WHERE id=:id');
        $os->execute(['id' => $orderId]);
        $oldStatus = $os->fetchColumn();
        if ($oldStatus !== $newStatus) {
            $upd = $this->pdo->prepare('UPDATE ' . $this->q($this->ordersTable) . ' SET status=:st, updated_at=NOW() WHERE id=:id');
            $upd->execute(['st' => $newStatus, 'id' => $orderId]);
            $eventKey = 'order:' . $orderId . ':status:' . $oldStatus . '->' . $newStatus . ':' . uniqid('', true);
            $this->writeStatusLog($companyId, $orderId, null, $oldStatus, $newStatus, 'system', $eventKey, 'Derived from item statuses: ' . implode(', ', $itemStatuses));
        }
    }

    private function updateOrderStatus(int $companyId, int $orderId, string $newStatus, string $eventKey, string $reason): void
    {
        $os = $this->pdo->prepare('SELECT status FROM ' . $this->q($this->ordersTable) . ' WHERE id=:id');
        $os->execute(['id' => $orderId]);
        $oldStatus = $os->fetchColumn();
        $upd = $this->pdo->prepare('UPDATE ' . $this->q($this->ordersTable) . ' SET status=:st, updated_at=NOW() WHERE id=:id');
        $upd->execute(['st' => $newStatus, 'id' => $orderId]);
        $this->writeStatusLog($companyId, $orderId, null, $oldStatus, $newStatus, 'system', $eventKey, $reason);
    }

    private function writeStatusLog(int $companyId, int $orderId, ?int $itemId, ?string $fromStatus, string $toStatus, string $source, string $eventKey, ?string $reason): void
    {
        $ins = $this->pdo->prepare('INSERT INTO ' . $this->q($this->logsTable) . ' (company_id, order_id, order_item_id, from_status, to_status, source, event_key, reason, created_at) VALUES (:cid, :oid, :iid, :fs, :ts, :src, :ek, :rsn, NOW())');
        $ins->execute(['cid' => $companyId, 'oid' => $orderId, 'iid' => $itemId, 'fs' => $fromStatus, 'ts' => $toStatus, 'src' => $source, 'ek' => $eventKey, 'rsn' => $reason]);
    }

    private function loadOrder(int $companyId, int $orderId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->q($this->ordersTable) . ' WHERE id=:id AND company_id=:cid LIMIT 1');
        $stmt->execute(['id' => $orderId, 'cid' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw PublishException::orderNotFound($companyId, $orderId); }
        return $this->formatOrderRow($row);
    }

    private function loadItem(int $companyId, int $orderId, int $itemId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->q($this->itemsTable) . ' WHERE id=:id AND company_id=:cid AND order_id=:oid LIMIT 1');
        $stmt->execute(['id' => $itemId, 'cid' => $companyId, 'oid' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw PublishException::orderNotFound($companyId, $orderId); }
        return $row;
    }

    private function findIdempotentOrder(int $companyId, string $idempotencyKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->q($this->ordersTable) . ' WHERE company_id=:cid AND idempotency_key=:ik LIMIT 1');
        $stmt->execute(['cid' => $companyId, 'ik' => $idempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->formatOrderRow($row) : null;
    }

    private function verifyArticleOwnership(int $companyId, int $articleId, int $articleVersionId): void
    {
        $articlesTable = $this->prefix . 'articles'; $versionsTable = $this->prefix . 'article_versions';
        $as = $this->pdo->prepare('SELECT id FROM ' . $this->q($articlesTable) . ' WHERE id=:aid AND company_id=:cid LIMIT 1');
        $as->execute(['aid' => $articleId, 'cid' => $companyId]);
        if (!$as->fetch()) { throw PublishException::articleNotBelongToCompany($articleId, $companyId); }
        $vs = $this->pdo->prepare('SELECT id FROM ' . $this->q($versionsTable) . ' WHERE id=:vid AND article_id=:aid LIMIT 1');
        $vs->execute(['vid' => $articleVersionId, 'aid' => $articleId]);
        if (!$vs->fetch()) { throw PublishException::articleVersionNotBelongToArticle($articleVersionId, $articleId); }
    }

    private function loadActiveMedia(array $mediaIds): array
    {
        $mediaTable = $this->prefix . 'media_resources';
        $in = implode(',', array_map('intval', $mediaIds));
        $stmt = $this->pdo->query('SELECT * FROM ' . $this->q($mediaTable) . ' WHERE id IN (' . $in . ')');
        $map = []; foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $map[(int) $row['id']] = $row; }
        return $map;
    }

    private function validateMaterialLink(array $mediaMap, string $link): void
    {
        $requiresVideoLink = false;
        foreach ($mediaMap as $media) {
            $attributes = json_decode((string) ($media['attributes_json'] ?? '{}'), true);
            if (is_array($attributes) && ($attributes['source_catalog'] ?? '') === '/video') {
                $requiresVideoLink = true;
                break;
            }
        }
        $validHttpLink = $link !== ''
            && strlen($link) <= 2048
            && preg_match('/^https?:\/\//i', $link) === 1;
        if ($requiresVideoLink) {
            if (!$validHttpLink) {
                throw PublishException::invalidVideoLink();
            }
            return;
        }
        if ($link !== '' && !$validHttpLink) {
            throw PublishException::invalidOriginalLink();
        }
    }

    private function calcSalePrice(string $costPrice): string
    {
        $cost = $costPrice;
        if (bccomp($cost, '0.00', 2) <= 0) { throw new \RuntimeException('Media cost must be positive.'); }
        $sale = bcmul($cost, number_format((new \app\common\supplier\MediaPricingService())->markupRate(), 2, '.', ''), 4);
        $sale = bcadd($sale, '0.0099', 2);
        return bcadd($sale, '0.00', 2);
    }

    private function buildPriceFingerprint(int $companyId, int $articleVersionId, array $sortedMediaIds, array $mediaMap): string
    {
        $parts = [(string) $companyId, (string) $articleVersionId];
        foreach ($sortedMediaIds as $mediaId) { $m = $mediaMap[$mediaId] ?? null; $parts[] = (string) $mediaId; $parts[] = $m['cost_price'] ?? '0.00'; }
        return hash('sha256', implode('|', $parts));
    }

    private function formatOrderRow(array $row): array
    {
        return ['id' => (int) $row['id'], 'order_no' => $row['order_no'], 'company_id' => (int) $row['company_id'], 'article_id' => (int) $row['article_id'], 'article_version_id' => (int) $row['article_version_id'], 'status' => $row['status'], 'total_amount' => self::fmt($row['total_amount']), 'frozen_amount' => self::fmt($row['frozen_amount']), 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
    }

    private function formatItemRow(array $row): array
    {
        $media = $this->formatMediaIdentity($row);
        return ['id' => (int) $row['id'], 'media_id' => $media['media_id'], 'media_name' => $media['media_name'], 'media_display_name' => $media['media_display_name'], 'sale_snapshot' => self::fmt($row['sale_snapshot']), 'status' => $row['status'], 'supplier_order_no' => $row['supplier_order_no'], 'published_url' => $row['published_url'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
    }

    private function loadOrderMediaItems(int $companyId, array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $orderIds)));
        $stmt = $this->pdo->prepare(
            'SELECT i.order_id, i.media_id, m.name AS media_name'
            . ' FROM ' . $this->q($this->itemsTable) . ' i'
            . ' LEFT JOIN ' . $this->q($this->mediaTable) . ' m ON m.id=i.media_id'
            . ' WHERE i.company_id=:cid AND i.order_id IN (' . implode(',', $ids) . ')'
            . ' ORDER BY i.order_id DESC, i.id ASC'
        );
        $stmt->execute(['cid' => $companyId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['order_id']][] = $this->formatMediaIdentity($row);
        }
        return $result;
    }

    private function formatMediaIdentity(array $row): array
    {
        $mediaId = (int) $row['media_id'];
        $name = trim((string) ($row['media_name'] ?? ''));
        return [
            'media_id' => $mediaId,
            'media_name' => $name !== '' ? $name : null,
            'media_display_name' => $name !== '' ? $name : '媒体 ID：' . $mediaId,
        ];
    }

    public static function fmt($value): string
    {
        if (is_string($value)) { $parts = explode('.', $value); $intPart = $parts[0] ?? '0'; $decPart = $parts[1] ?? '00'; return $intPart . '.' . str_pad(substr($decPart, 0, 2), 2, '0', STR_PAD_RIGHT); }
        return number_format((float) $value, 2, '.', '');
    }

    private function q(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) { throw new \RuntimeException('SQL identifier is invalid.'); }
        return '`' . $identifier . '`';
    }

    /**
     * Transition an order from funds_frozen to queued for supplier submission (T12).
     */
    public function queueForSubmit(int $companyId, int $orderId): void
    {
        $order = $this->loadOrder($companyId, $orderId);
        if ($order['status'] === 'queued') {
            return;
        }
        if ($order['status'] !== 'funds_frozen') {
            throw PublishException::invalidStateTransition($order['status'], 'queued');
        }
        $this->stateMachine->validateTransition('funds_frozen', 'queued');
        $this->pdo->beginTransaction();
        try {
            $upd = $this->pdo->prepare(
                'UPDATE ' . $this->q($this->ordersTable) . ' SET status = :st, updated_at = NOW() WHERE id = :id'
            );
            $upd->execute(['st' => 'queued', 'id' => $orderId]);
            $this->writeStatusLog($companyId, $orderId, null, 'funds_frozen', 'queued', 'system',
                'order:' . $orderId . ':queued_for_submit', 'Queued for supplier submission');

            $items = $this->pdo->prepare(
                'SELECT `id`,`status` FROM ' . $this->q($this->itemsTable)
                . ' WHERE company_id = :cid AND order_id = :oid'
            );
            $items->execute(['cid' => $companyId, 'oid' => $orderId]);
            $advance = $this->pdo->prepare(
                'UPDATE ' . $this->q($this->itemsTable)
                . ' SET status = :st, updated_at = NOW() WHERE id = :id AND company_id = :cid'
            );
            foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
                if ($item['status'] !== 'funds_frozen') {
                    continue;
                }
                $itemId = (int) $item['id'];
                $advance->execute(['st' => 'queued', 'id' => $itemId, 'cid' => $companyId]);
                $this->writeStatusLog(
                    $companyId,
                    $orderId,
                    $itemId,
                    'funds_frozen',
                    'queued',
                    'system',
                    'order:' . $orderId . ':item:' . $itemId . ':queued_for_submit',
                    'Queued for supplier submission'
                );
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    public function markSubmissionQueueFailed(int $companyId, int $orderId): void
    {
        $order = $this->loadOrder($companyId, $orderId);
        if ($order['status'] === 'manual_review') {
            return;
        }
        if (!in_array($order['status'], ['funds_frozen', 'queued'], true)) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $items = $this->pdo->prepare(
                'SELECT `id`,`status` FROM ' . $this->q($this->itemsTable)
                . ' WHERE company_id = :cid AND order_id = :oid'
            );
            $items->execute(['cid' => $companyId, 'oid' => $orderId]);
            $itemRows = $items->fetchAll(PDO::FETCH_ASSOC);

            $updateItem = $this->pdo->prepare(
                'UPDATE ' . $this->q($this->itemsTable)
                . ' SET status = :status, updated_at = NOW()'
                . ' WHERE id = :id AND company_id = :cid AND status IN (\'funds_frozen\',\'queued\')'
            );
            foreach ($itemRows as $item) {
                if (!in_array($item['status'], ['funds_frozen', 'queued'], true)) {
                    continue;
                }
                $itemId = (int) $item['id'];
                $updateItem->execute(['status' => 'manual_review', 'id' => $itemId, 'cid' => $companyId]);
                $this->writeStatusLog(
                    $companyId,
                    $orderId,
                    $itemId,
                    $item['status'],
                    'manual_review',
                    'system',
                    'order:' . $orderId . ':item:' . $itemId . ':submission_queue_failed',
                    'Supplier submission queue was unavailable; no supplier result was recorded.'
                );
            }

            $updateOrder = $this->pdo->prepare(
                'UPDATE ' . $this->q($this->ordersTable)
                . ' SET status = :status, updated_at = NOW()'
                . ' WHERE id = :id AND company_id = :cid AND status IN (\'funds_frozen\',\'queued\')'
            );
            $updateOrder->execute(['status' => 'manual_review', 'id' => $orderId, 'cid' => $companyId]);
            $this->writeStatusLog(
                $companyId,
                $orderId,
                null,
                $order['status'],
                'manual_review',
                'system',
                'order:' . $orderId . ':submission_queue_failed',
                'Supplier submission queue was unavailable; order requires recovery.'
            );
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function cancelUnsubmittedQueuedOrder(
        int $companyId,
        int $orderId,
        int $actorId,
        string $ip = ''
    ): array {
        $order = $this->loadOrder($companyId, $orderId);
        if ($order['status'] === 'cancelled') {
            return $order;
        }
        if (!in_array($order['status'], ['queued', 'manual_review'], true)) {
            throw PublishException::cancelNotAllowed($order['status']);
        }

        $items = $this->pdo->prepare(
            'SELECT `id`,`status`,`supplier_order_no`,`supplier_submit_request_id`,`submitted_at`'
            . ' FROM ' . $this->q($this->itemsTable)
            . ' WHERE company_id = :cid AND order_id = :oid ORDER BY id ASC'
        );
        $items->execute(['cid' => $companyId, 'oid' => $orderId]);
        $itemRows = $items->fetchAll(PDO::FETCH_ASSOC);
        foreach ($itemRows as $item) {
            if (trim((string) ($item['supplier_order_no'] ?? '')) !== ''
                || trim((string) ($item['supplier_submit_request_id'] ?? '')) !== ''
                || $item['submitted_at'] !== null) {
                throw PublishException::cancelNotAllowed($order['status']);
            }
        }

        if ($order['status'] === 'queued') {
            $this->markSubmissionQueueFailed($companyId, $orderId);
            foreach ($itemRows as &$item) {
                if ($item['status'] === 'queued' || $item['status'] === 'funds_frozen') {
                    $item['status'] = 'manual_review';
                }
            }
            unset($item);
        }

        foreach ($itemRows as $item) {
            if ($item['status'] === 'cancelled') {
                continue;
            }
            if ($item['status'] !== 'manual_review') {
                throw PublishException::cancelNotAllowed($item['status']);
            }
            $this->transitionItem(
                $companyId,
                $orderId,
                (int) $item['id'],
                'cancelled',
                'system',
                'Unsubmitted queued order closed by operator ' . $actorId,
                $ip
            );
        }

        return $this->loadOrder($companyId, $orderId);
    }
}

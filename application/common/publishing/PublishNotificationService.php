<?php

declare(strict_types=1);

namespace app\common\publishing;

use PDO;
use RuntimeException;

/**
 * T14: 閫氱煡鏈嶅姟涓庡彂甯冪粨鏋滄秷璐? *
 * - 鍒涘缓閫氱煡璁板綍锛堢姸鎬佸彉鍖栥€佺粓鎬佺姸鎬佽揪鎴愶紝
 * - 鏇存柊鍙戝竷缁撴灉锛坧ublished_url 绛夛級
 * - 鏌ヨ閫氱煡鍒楄〃銆佹爣璁板凡璇? */
final class PublishNotificationService
{
    private PDO $pdo;
    private string $prefix;
    private string $notificationsTable;
    private string $itemsTable;

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
        $this->notificationsTable = $prefix . 'publish_notifications';
        $this->itemsTable = $prefix . 'publish_order_items';
    }

    /**
     * Factory: create instance using ThinkPHP config (for API controllers).
     */
    public static function create(): self
    {
        $prefix = \think\Config::get("database.prefix") ?: "fa_";
        return new self(\think\Db::connect()->getPdo(), $prefix);
    }

    /**
     * 鍒涘缓涓€鏉￠€氱煡璁板綍銆?     *
     * @param string $type 閫氱煡绫诲瀷锛歱ublished/rejected/order_status
     * @param array|null $content 闄勫姞鍐呭锛圝SON锛?     */
    public function createNotification(
        int $companyId,
        int $orderId,
        ?int $orderItemId,
        string $type,
        string $title,
        ?array $content = null
    ): int {
        $ins = $this->pdo->prepare(
            'INSERT INTO ' . $this->q($this->notificationsTable)
            . ' (company_id, order_id, order_item_id, type, title, content, is_read, created_at)'
            . ' VALUES (:cid, :oid, :iid, :tp, :tl, :ct, 0, NOW())'
        );
        $ins->execute([
            'cid' => $companyId,
            'oid' => $orderId,
            'iid' => $orderItemId,
            'tp' => $type,
            'tl' => $title,
            'ct' => $content !== null ? json_encode($content, JSON_UNESCAPED_UNICODE) : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 鏇存柊鍙戝竷缁撴灉锛氬皢渚涘簲鍟嗗搷搴斾腑鐨?popId 鍐欏叆 published_url銆?     *
     * 褰撳悓姝ユ湇鍔″彂鐜颁竴涓」鐩姸鎬佸彉涓?published 鏃惰皟鐢ㄣ€?     *
     * @param array $supplierItems 渚涘簲鍟嗗搷搴斾腑鐨?items 鏁扮粍锛屾瘡椤瑰惈 mediaId, popId, status
     */
    public function processPublishResults(
        int $companyId,
        int $orderId,
        array $orderItems,
        array $supplierItems
    ): array {
        // 鏋勫缓渚涘簲鍟嗘暟鎹€熸煡琛?        $supplierByMediaId = [];
        foreach ($supplierItems as $si) {
            $mid = $si['mediaId'] ?? '';
            if ($mid !== '') {
                $supplierByMediaId[$mid] = $si;
            }
        }

        // 鏋勫缓鏈湴椤圭洰 -> 澶栭儴 mediaId 鏄犲皠
        $externalIds = $this->resolveExternalIds($orderItems);

        $updated = 0;
        foreach ($orderItems as $item) {
            $itemId = (int) $item['id'];
            $externalId = $externalIds[$itemId] ?? null;
            if ($externalId === null) {
                continue;
            }

            $supplierItem = $supplierByMediaId[$externalId] ?? null;
            if ($supplierItem === null) {
                continue;
            }

            // 鏇存柊 published_url 锛堝彧鍦?popId 鏈夊€兼椂锛?            $popId = trim((string) ($supplierItem['popId'] ?? ''));
            if ($popId !== '') {
                $upd = $this->pdo->prepare(
                    'UPDATE ' . $this->q($this->itemsTable)
                    . ' SET published_url = :purl, updated_at = NOW()'
                    . ' WHERE id = :id AND company_id = :cid'
                );
                $upd->execute([
                    'purl' => $popId,
                    'id' => $itemId,
                    'cid' => $companyId,
                ]);
                $updated++;
            }
        }

        return ['updated_urls' => $updated];
    }

    /**
     * 鏌ヨ閫氱煡鍒楄〃銆?     */
    public function listNotifications(
        int $companyId,
        int $page = 1,
        int $perPage = 20,
        ?bool $unreadOnly = null
    ): array {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $where = 'WHERE company_id = :cid';
        $params = ['cid' => $companyId];

        if ($unreadOnly === true) {
            $where .= ' AND is_read = 0';
        }

        $cs = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . $this->q($this->notificationsTable) . ' ' . $where
        );
        $cs->execute($params);
        $total = (int) $cs->fetchColumn();

        $params['lim'] = $perPage;
        $params['off'] = $offset;
        $ls = $this->pdo->prepare(
            'SELECT * FROM ' . $this->q($this->notificationsTable) . ' ' . $where
            . ' ORDER BY id DESC LIMIT :lim OFFSET :off'
        );
        $ls->bindValue('cid', $companyId, PDO::PARAM_INT);
        $ls->bindValue('lim', $perPage, PDO::PARAM_INT);
        $ls->bindValue('off', $offset, PDO::PARAM_INT);
        $ls->execute();

        $items = [];
        foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = $this->formatNotificationRow($row);
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * 鏍囪涓€鏉￠€氱煡涓哄凡璇汇€?     */
    public function markAsRead(int $companyId, int $notificationId): bool
    {
        $upd = $this->pdo->prepare(
            'UPDATE ' . $this->q($this->notificationsTable)
            . ' SET is_read = 1, read_at = NOW()'
            . ' WHERE id = :id AND company_id = :cid AND is_read = 0'
        );
        $upd->execute(['id' => $notificationId, 'cid' => $companyId]);
        return $upd->rowCount() > 0;
    }

    /**
     * 鏍囪鎸囧畾璁㈠崟鐨勬墍鏈夐€氱煡涓哄凡璇汇€?     */
    public function markAllAsRead(int $companyId, int $orderId): int
    {
        $upd = $this->pdo->prepare(
            'UPDATE ' . $this->q($this->notificationsTable)
            . ' SET is_read = 1, read_at = NOW()'
            . ' WHERE company_id = :cid AND order_id = :oid AND is_read = 0'
        );
        $upd->execute(['cid' => $companyId, 'oid' => $orderId]);
        return $upd->rowCount();
    }

    /**
     * 缁熻鏈閫氱煡鏁般€?     */
    public function countUnread(int $companyId, ?int $orderId = null): int
    {
        if ($orderId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . $this->q($this->notificationsTable)
                . ' WHERE company_id = :cid AND order_id = :oid AND is_read = 0'
            );
            $stmt->execute(['cid' => $companyId, 'oid' => $orderId]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . $this->q($this->notificationsTable)
                . ' WHERE company_id = :cid AND is_read = 0'
            );
            $stmt->execute(['cid' => $companyId]);
        }
        return (int) $stmt->fetchColumn();
    }

    /**
     * 閫氳繃 media_resources 琛ㄨВ鏋愭湰鍦伴」鐩?ID 鍒板閮?mediaId 鐨勬槧灏勩€?     */
    private function resolveExternalIds(array $orderItems): array
    {
        if ($orderItems === []) {
            return [];
        }

        $itemIds = array_map(fn($i) => (int) $i['id'], $orderItems);
        $in = implode(',', $itemIds);

        $mediaTable = $this->prefix . 'media_resources';

        $stmt = $this->pdo->prepare(
            'SELECT oi.id, m.external_id'
            . ' FROM ' . $this->q($this->itemsTable) . ' oi'
            . ' LEFT JOIN ' . $this->q($mediaTable) . ' m ON oi.media_id = m.id'
            . ' WHERE oi.id IN (' . $in . ')'
        );
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $externalId = trim((string) ($row['external_id'] ?? ''));
            if ($externalId !== '') {
                $result[(int) $row['id']] = $externalId;
            }
        }
        return $result;
    }

    private function formatNotificationRow(array $row): array
    {
        $content = null;
        if ($row['content'] !== null && is_string($row['content'])) {
            $decoded = json_decode($row['content'], true);
            if (is_array($decoded)) {
                $content = $decoded;
            }
        }

        return [
            'id' => (int) $row['id'],
            'company_id' => (int) $row['company_id'],
            'order_id' => (int) $row['order_id'],
            'order_item_id' => $row['order_item_id'] !== null ? (int) $row['order_item_id'] : null,
            'type' => $row['type'],
            'title' => $row['title'],
            'content' => $content,
            'is_read' => (bool) $row['is_read'],
            'created_at' => $row['created_at'],
            'read_at' => $row['read_at'],
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

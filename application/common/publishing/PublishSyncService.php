<?php

declare(strict_types=1);

namespace app\common\publishing;

use app\common\supplier\contract\SupplierAdapter;
use app\common\supplier\dto\OrderQuery;
use app\common\supplier\dto\OrderResult;
use PDO;
use RuntimeException;

final class PublishSyncService
{
    private PDO $pdo;
    private PublishOrderService $orderService;
    private PublishNotificationService $notificationService;
    private string $prefix;

    public function __construct(
        PDO $pdo,
        PublishOrderService $orderService,
        string $prefix,
        ?PublishNotificationService $notificationService = null
    ) {
        $this->pdo = $pdo;
        $this->orderService = $orderService;
        $this->notificationService = $notificationService ?? new PublishNotificationService($pdo, $prefix);
        $this->prefix = $prefix;
    }

    public function syncOrder(
        SupplierAdapter $adapter,
        int $companyId,
        int $orderId,
        string $ip = ""
    ): array {
        $order = $this->orderService->getDetail($companyId, $orderId);
        $order['items'] = $this->loadSyncItems($companyId, $orderId);
        $externalOrderNo = $this->extractExternalOrderNo($order["items"]);
        if ($externalOrderNo === null || $externalOrderNo === "") {
            return ["status" => "skipped", "synced" => 0, "unchanged" => 0, "failed" => 0, "message" => "No external order number found; order not yet submitted."];
        }

        $syncableStatuses = ["submitted", "processing"];
        $syncableItems = [];
        foreach ($order["items"] as $item) {
            if (in_array($item["status"], $syncableStatuses, true)) {
                $syncableItems[] = $item;
            }
        }
        if ($syncableItems === []) {
            return ["status" => "skipped", "synced" => 0, "unchanged" => 0, "failed" => 0, "message" => "No items in syncable status (submitted/processing)."];
        }

        $channelCode = '';
        foreach ($syncableItems as $item) {
            $code = trim((string) ($item["channel_code"] ?? ""));
            if ($code !== "") {
                $channelCode = $code;
                break;
            }
        }

        $catalog = '';
        foreach ($syncableItems as $item) {
            $attributes = json_decode((string) ($item['attributes_json'] ?? '{}'), true);
            if (!is_array($attributes)) {
                continue;
            }
            $catalog = trim((string) ($attributes['source_catalog'] ?? ''));
            if ($catalog !== '') {
                break;
            }
        }

        try {
            $result = $adapter->queryOrder(new OrderQuery($externalOrderNo, "", $channelCode, $catalog));
        } catch (\Throwable $e) {
            $this->writeSyncFailureLog($companyId, $orderId, $syncableItems, $e->getMessage());
            return ["status" => "query_failed", "synced" => 0, "unchanged" => 0, "failed" => count($syncableItems), "message" => "Supplier query failed: " . $e->getMessage()];
        }

        if (!$result->supplierStatusKnown()) {
            $failReason = $result->failure() !== null ? $result->failure()->redactedReason() : "Supplier returned unknown status";
            $this->writeSyncFailureLog($companyId, $orderId, $syncableItems, $failReason);
            return ["status" => "unknown_status", "synced" => 0, "unchanged" => 0, "failed" => count($syncableItems), "message" => "Supplier status unknown: " . $failReason];
        }

        $supplierItems = $this->extractSupplierItems($result);
        if ($supplierItems === []) {
            return ["status" => "no_items", "synced" => 0, "unchanged" => 0, "failed" => count($syncableItems), "message" => "Supplier returned no items in response."];
        }

        $itemByExternalId = $this->buildExternalIdMap($syncableItems);
        $rawJson = json_encode($result->rawPayload() !== null ? $result->rawPayload()->all() : [], JSON_UNESCAPED_UNICODE);
        $synced = 0; $unchanged = 0; $failed = 0;
        $errors = [];
        $oldOrderStatus = $order["order"]["status"];

        foreach ($supplierItems as $supplierItem) {
            $mediaId = $supplierItem["mediaId"] ?? "";
            if ($mediaId === "") { $failed++; $errors[] = "Supplier item missing mediaId"; continue; }
            $localItem = $itemByExternalId[$mediaId] ?? null;
            if ($localItem === null) { $failed++; $errors[] = "No local item matched for external mediaId: " . $mediaId; continue; }
            $supplierStatus = $supplierItem["status"] ?? "";
            $newStatus = $this->mapStatus($supplierStatus);
            $supplierPopId = trim((string) ($supplierItem["popId"] ?? ""));
            $supplierPublishedUrl = trim((string) ($supplierItem["publishedUrl"] ?? ""));
            $refuseReason = trim((string) ($supplierItem["refuseReason"] ?? ""));
            $this->updateSyncMetadata((int) $localItem["id"], $companyId, $rawJson);
            if ($newStatus === $localItem["status"]) { $unchanged++; continue; }
            $reason = "Sync: supplier status " . $supplierStatus . " -> " . $newStatus;
            if ($newStatus === "rejected" && $refuseReason !== "") {
                $reason .= " (supplier reason: " . $refuseReason . ")";
            }
            try {
                if ($newStatus === "published" && ($localItem["status"] ?? "") === "submitted") {
                    $this->orderService->transitionItem(
                        $companyId,
                        $orderId,
                        (int) $localItem["id"],
                        "processing",
                        "supplier",
                        "Sync: supplier status " . $supplierStatus . " -> processing",
                        $ip
                    );
                }
                $this->orderService->transitionItem($companyId, $orderId, (int) $localItem["id"], $newStatus, "supplier", $reason, $ip);
                $synced++;
                if ($newStatus === "published" && ($supplierPublishedUrl !== "" || $supplierPopId !== "")) {
                    $itemsTable = $this->prefix . "publish_order_items";
                    $updUrl = $this->pdo->prepare("UPDATE " . $this->q($itemsTable) . " SET published_url = :purl, updated_at = NOW() WHERE id = :id AND company_id = :cid");
                    $updUrl->execute([
                        "purl" => $supplierPublishedUrl !== "" ? $supplierPublishedUrl : $supplierPopId,
                        "id" => (int) $localItem["id"],
                        "cid" => $companyId,
                    ]);
                }
                $this->handleTerminalNotification($companyId, $orderId, (int) $localItem["id"], $newStatus, $supplierStatus);
            } catch (\Throwable $e) { $failed++; $errors[] = "Transition failed for item " . $localItem["id"] . ": " . $e->getMessage(); }
        }

        $updatedOrder = $this->orderService->getDetail($companyId, $orderId);
        $newOrderStatus = $updatedOrder["order"]["status"];
        if ($oldOrderStatus !== $newOrderStatus) {
            $this->createOrderStatusNotification($companyId, $orderId, $oldOrderStatus, $newOrderStatus);
        }

        return ["status" => $synced > 0 ? "synced" : ($failed > 0 ? "partial_failure" : "unchanged"), "synced" => $synced, "unchanged" => $unchanged, "failed" => $failed, "message" => $errors !== [] ? implode("; ", $errors) : "Sync complete."];
    }

    public function findOrdersNeedingSync(int $limit = 50): array
    {
        $itemsTable = $this->prefix . "publish_order_items";
        $stmt = $this->pdo->prepare("SELECT DISTINCT oi.company_id, oi.order_id FROM " . $this->q($itemsTable) . " oi WHERE oi.status IN (:s1, :s2) AND oi.supplier_submit_request_id IS NOT NULL AND oi.supplier_submit_request_id != '' AND (oi.last_synced_at IS NULL OR oi.last_synced_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)) ORDER BY oi.company_id ASC, oi.order_id ASC LIMIT :lim");
        $stmt->bindValue("s1", "submitted"); $stmt->bindValue("s2", "processing"); $stmt->bindValue("lim", $limit, PDO::PARAM_INT);
        $stmt->execute();
        $orders = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $orders[] = ["company_id" => (int) $row["company_id"], "order_id" => (int) $row["order_id"]];
        }
        return $orders;
    }

    private function mapStatus(string $supplierStatus): string
    {
        return match ($supplierStatus) {
            "0" => "submitted",
            "1" => "processing",
            // Official API doc: 0=待审核, 1=发布中, 2=已发布, 3=拒稿
            "2" => "published",
            "3" => "rejected",
            // Value 4 is not defined in the official API document.
            "4" => "manual_review",
            default => "manual_review",
        };
    }

    private function extractExternalOrderNo(array $items): ?string
    {
        foreach ($items as $item) {
            $no = $item["supplier_submit_request_id"] ?? null;
            if ($no !== null && trim((string) $no) !== "") { return trim((string) $no); }
        }
        return null;
    }

    private function loadSyncItems(int $companyId, int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.`id`,i.`status`,i.`sale_snapshot`,i.`supplier_submit_request_id`,c.`code` AS channel_code,'
            . ' m.`attributes_json`'
            . ' FROM ' . $this->q($this->prefix . 'publish_order_items')
            . ' i LEFT JOIN ' . $this->q($this->prefix . 'media_resources')
            . ' m ON m.`id`=i.`media_id`'
            . ' LEFT JOIN ' . $this->q($this->prefix . 'channels') . ' c ON c.`id`=m.`channel_id`'
            . ' WHERE i.`company_id`=:cid AND i.`order_id`=:oid ORDER BY i.`id` ASC'
        );
        $stmt->execute(['cid' => $companyId, 'oid' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function extractSupplierItems(OrderResult $result): array
    {
        $rawPayload = $result->rawPayload();
        if ($rawPayload === null) { return []; }
        $payload = $rawPayload->all();
        $items = $payload["items"] ?? [];
        if (!is_array($items)) { return []; }
        $result2 = [];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $result2[] = [
                "mediaId" => trim((string) ($item["mediaId"] ?? "")),
                "popId" => trim((string) ($item["publishedUrl"] ?? $item["url"] ?? $item["popId"] ?? "")),
                "publishedUrl" => trim((string) ($item["publishedUrl"] ?? $item["url"] ?? "")),
                "refuseReason" => trim((string) ($item["refuseReason"] ?? "")),
                "status" => trim((string) ($item["status"] ?? "")),
            ];
        }
        return $result2;
    }

    private function buildExternalIdMap(array $items): array
    {
        if ($items === []) { return []; }
        $itemIds = array_map(fn($i) => (int) $i["id"], $items);
        $in = implode(",", $itemIds);
        $mediaTable = $this->prefix . "media_resources";
        $itemsTable = $this->prefix . "publish_order_items";
        $stmt = $this->pdo->prepare("SELECT oi.id, m.external_id FROM " . $this->q($itemsTable) . " oi LEFT JOIN " . $this->q($mediaTable) . " m ON oi.media_id = m.id WHERE oi.id IN (" . $in . ")");
        $stmt->execute();
        $idToExternal = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $externalId = trim((string) ($row["external_id"] ?? ""));
            if ($externalId !== "") { $idToExternal[$externalId] = (int) $row["id"]; }
        }
        $result = [];
        foreach ($items as $item) {
            $itemId = (int) $item["id"];
            foreach ($idToExternal as $extId => $mappedId) { if ($mappedId === $itemId) { $result[$extId] = $item; break; } }
        }
        return $result;
    }

    private function updateSyncMetadata(int $itemId, int $companyId, string $rawJson): void
    {
        $itemsTable = $this->prefix . "publish_order_items";
        $upd = $this->pdo->prepare("UPDATE " . $this->q($itemsTable) . " SET last_synced_at = NOW(), supplier_sync_raw = :raw, updated_at = NOW() WHERE id = :id AND company_id = :cid");
        $upd->execute(["raw" => $rawJson, "id" => $itemId, "cid" => $companyId]);
    }

    private function writeSyncFailureLog(int $companyId, int $orderId, array $items, string $reason): void
    {
        $logsTable = $this->prefix . "order_status_logs";
        $ins = $this->pdo->prepare("INSERT INTO " . $this->q($logsTable) . " (company_id, order_id, order_item_id, from_status, to_status, source, event_key, reason, created_at) VALUES (:cid, :oid, :iid, :fs, :ts, :src, :ek, :rsn, NOW())");
        foreach ($items as $item) {
            $itemId = (int) $item["id"];
            $eventKey = "order:" . $orderId . ":item:" . $itemId . ":sync_failed:" . uniqid("", true);
            $ins->execute(["cid" => $companyId, "oid" => $orderId, "iid" => $itemId, "fs" => $item["status"], "ts" => $item["status"], "src" => "supplier", "ek" => $eventKey, "rsn" => "Sync failed: " . $reason]);
            $this->updateSyncMetadata($itemId, $companyId, json_encode(["error" => $reason], JSON_UNESCAPED_UNICODE));
        }
    }

    private function handleTerminalNotification(int $companyId, int $orderId, int $itemId, string $newStatus, string $supplierStatus): void
    {
        $terminals = ["published", "rejected", "manual_review", "cancelled", "refunded", "after_sale"];
        if (!in_array($newStatus, $terminals, true)) { return; }
        $label = match ($newStatus) {
            "published" => "published",
            "rejected" => "rejected",
            "manual_review" => "manual_review",
            "cancelled" => "cancelled",
            "refunded" => "refunded",
            default => $newStatus,
        };
        $title = "Order item status changed: " . $label;
        $this->notificationService->createNotification($companyId, $orderId, $itemId, $newStatus, $title, ["order_id" => $orderId, "item_id" => $itemId, "new_status" => $newStatus, "supplier_status" => $supplierStatus]);
    }

    private function createOrderStatusNotification(int $companyId, int $orderId, string $oldStatus, string $newStatus): void
    {
        $label = match ($newStatus) {
            "published" => "all published", "partially_completed" => "partially completed", "rejected" => "rejected", "manual_review" => "manual_review", "cancelled" => "cancelled", "refunded" => "refunded",
            default => $newStatus,
        };
        $this->notificationService->createNotification($companyId, $orderId, null, "order_status", "Order status changed: " . $label, ["order_id" => $orderId, "old_status" => $oldStatus, "new_status" => $newStatus]);
    }

    private function q(string $identifier): string
    {
        if (preg_match("/^[A-Za-z0-9_]+$/D", $identifier) !== 1) { throw new RuntimeException("SQL identifier is invalid."); }
        return "`" . $identifier . "`";
    }
}

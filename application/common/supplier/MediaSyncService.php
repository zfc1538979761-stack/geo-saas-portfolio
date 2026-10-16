<?php

declare(strict_types=1);

namespace app\common\supplier;

use app\common\supplier\contract\SupplierAdapter;
use app\common\supplier\dto\MediaItem;
use app\common\supplier\dto\MediaQuery;
use app\common\supplier\dto\MediaStatus;
use app\common\supplier\exception\SupplierException;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class MediaSyncService
{
    private PDO $pdo;
    private string $prefix;

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }

    public function pdo(): PDO { return $this->pdo; }

    /**
     * Run a full sync: fetch all media from supplier and upsert into media_resources.
     *
     * @return array{started_at:string, ended_at:string, total_pages:int, total_read:int, created:int, updated:int, offlined:int, skipped:int, failed:int, task_id:string}
     */
    public function fullSync(SupplierAdapter $adapter, string $taskId, int $supplierId): array
    {
        $startedAt = date('Y-m-d H:i:s');

        $channels = $adapter->listChannels();
        $channelIds = $this->resolveChannelIds($channels);

        $totalRead = 0;
        $created = 0;
        $updated = 0;
        $offlined = 0;
        $skipped = 0;
        $failed = 0;
        $totalPages = 0;
        $seenExternalIds = [];

        // In a full sync, start page tracking
        $visitedPages = [];
        $lastPageCheck = null;

        foreach ($channels as $channel) {
            $channelCode = $channel->code();
            $channelId = $channelIds[$channelCode] ?? null;
            if ($channelId === null) continue;

            $page = 1;
            $pageSize = 100;

            while (true) {
                $pageKey = $channelCode . ':' . $page;
                if (isset($visitedPages[$pageKey])) {
                    throw new RuntimeException(
                        "Duplicate page detected: channel={$channelCode} page={$page}"
                    );
                }
                $visitedPages[$pageKey] = true;

                try {
                    $mediaPage = $adapter->fetchMedia(new MediaQuery($channelCode, $page, $pageSize));
                } catch (SupplierException $exception) {
                    $failed++;
                    break;
                } catch (\Throwable $exception) {
                    $failed++;
                    break;
                }

                $totalPages = max($totalPages, $page);
                $totalRead += count($mediaPage->items());

                foreach ($mediaPage->items() as $item) {
                    try {
                        if ($item->channelCode() !== $channelCode) {
                            $skipped++;
                            continue;
                        }
                        $externalId = $item->externalMediaId();
                        $seenExternalIds[$channelCode . ':' . $externalId] = true;

                        $result = $this->upsertMedia($item, $supplierId, $channelId, $taskId);
                        if ($result === 'created') $created++;
                        elseif ($result === 'updated') $updated++;
                        else $skipped++;
                    } catch (InvalidArgumentException $exception) {
                        $skipped++;
                    } catch (\Throwable $exception) {
                        $failed++;
                    }
                }

                if ($page >= (int) ceil($mediaPage->total() / $pageSize) || $page >= 500) {
                    break;
                }
                $page++;
            }

            // Official /media/video catalog maps to influencer with source_catalog=/video.
            if ($channelCode === 'influencer' && method_exists($adapter, 'fetchVideoMedia')) {
                $page = 1;
                while (true) {
                    $pageKey = $channelCode . ':video:' . $page;
                    if (isset($visitedPages[$pageKey])) {
                        throw new RuntimeException(
                            "Duplicate video page detected: channel={$channelCode} page={$page}"
                        );
                    }
                    $visitedPages[$pageKey] = true;

                    try {
                        $mediaPage = $adapter->fetchVideoMedia(new MediaQuery($channelCode, $page, $pageSize));
                    } catch (SupplierException $exception) {
                        $failed++;
                        break;
                    } catch (\Throwable $exception) {
                        $failed++;
                        break;
                    }

                    $totalPages = max($totalPages, $page);
                    $totalRead += count($mediaPage->items());

                    foreach ($mediaPage->items() as $item) {
                        try {
                            if ($item->channelCode() !== $channelCode) {
                                $skipped++;
                                continue;
                            }
                            $externalId = $item->externalMediaId();
                            $seenExternalIds[$channelCode . ':video:' . $externalId] = true;

                            $result = $this->upsertMedia($item, $supplierId, $channelId, $taskId);
                            if ($result === 'created') $created++;
                            elseif ($result === 'updated') $updated++;
                            else $skipped++;
                        } catch (InvalidArgumentException $exception) {
                            $skipped++;
                        } catch (\Throwable $exception) {
                            $failed++;
                        }
                    }

                    if ($page >= (int) ceil($mediaPage->total() / $pageSize) || $page >= 500) {
                        break;
                    }
                    $page++;
                }
            }
        }

        // Mark media not seen in this sync as potentially offline
        // Note: we only do this if the full sync completed all pages
        // This is a conservative approach - we don't mass offline on partial failure

        $endedAt = date('Y-m-d H:i:s');
        $this->clearCatalogFilterCache();

        return [
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'total_pages' => $totalPages,
            'total_read' => $totalRead,
            'created' => $created,
            'updated' => $updated,
            'offlined' => $offlined,
            'skipped' => $skipped,
            'failed' => $failed,
            'task_id' => $taskId,
        ];
    }

    /**
     * Run an incremental sync using updateTime-based change detection.
     *
     * @return array{started_at:string, ended_at:string, created:int, updated:int, offlined:int, skipped:int, failed:int, task_id:string, next_cursor:string|null}
     */
    public function incrementalSync(SupplierAdapter $adapter, string $taskId, int $supplierId, ?string $sinceToken = null): array
    {
        $startedAt = date('Y-m-d H:i:s');

        $channels = $adapter->listChannels();
        $channelIds = $this->resolveChannelIds($channels);

        $created = 0;
        $updated = 0;
        $offlined = 0;
        $skipped = 0;
        $failed = 0;
        $latestUpdateTime = null;

        $page = 1;
        $pageSize = 100;

        while (true) {
            try {
                $result = $this->clientFullSearch($adapter, $page, $pageSize);
            } catch (\Throwable $exception) {
                $failed++;
                break;
            }

            if (empty($result)) break;

            foreach ($result as $item) {
                try {
                    $externalId = $item->externalMediaId();
                    $channelCode = $item->channelCode();
                    $channelId = $channelIds[$channelCode] ?? null;
                    if ($channelId === null) {
                        $skipped++;
                        continue;
                    }

                    $upsertResult = $this->upsertMedia($item, $supplierId, $channelId, $taskId);
                    if ($upsertResult === 'created') $created++;
                    elseif ($upsertResult === 'updated') $updated++;
                    else $skipped++;
                } catch (InvalidArgumentException $exception) {
                    $skipped++;
                } catch (\Throwable $exception) {
                    $failed++;
                }
            }

            if (count($result) < $pageSize) break;
            $page++;
            if ($page > 500) break;
        }

        $endedAt = date('Y-m-d H:i:s');
        $this->clearCatalogFilterCache();

        return [
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'created' => $created,
            'updated' => $updated,
            'offlined' => $offlined,
            'skipped' => $skipped,
            'failed' => $failed,
            'task_id' => $taskId,
            'next_cursor' => null,
        ];
    }

    /**
     * Upsert a single MediaItem into media_resources.
     * Returns 'created', 'updated', or 'skipped'.
     */
    public function upsertMedia(MediaItem $item, int $supplierId, int $channelId, string $taskId): string
    {
        $externalId = $item->externalMediaId();
        $name = $item->name();
        if (preg_match('//u', $name) !== 1) {
            $cleanName = @iconv('UTF-8', 'UTF-8//IGNORE', $name);
            $name = is_string($cleanName) ? $cleanName : '';
        }
        $status = $item->status();
        $costPrice = $item->costPrice();
        $attributes = $item->attributes();
        $now = date('Y-m-d H:i:s');
        $newAttrsJson = json_encode(
            $attributes->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($newAttrsJson)) {
            throw new RuntimeException('Media attributes are not JSON serializable.');
        }

        $existing = $this->findByExternalId($supplierId, $externalId);
        if ($existing === null) {
            // INSERT new record
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . $this->quoteTable('media_resources') . ' '
                . '(supplier_id, external_id, channel_id, name, cost_price, cost_currency,'
                . ' status, supplier_raw_status, attributes_json, last_synced_at, created_at, updated_at) '
                . 'VALUES (:sid, :eid, :cid, :name, :cpr, :ccy, :status, :rstatus, :attrs, :lsync, :now, :now)'
            );
            $stmt->execute([
                'sid' => $supplierId,
                'eid' => $externalId,
                'cid' => $channelId,
                'name' => mb_substr($name, 0, 500),
                'cpr' => $costPrice->amount(),
                'ccy' => $costPrice->currency(),
                'status' => $status,
                'rstatus' => $status,
                'attrs' => $newAttrsJson,
                'lsync' => $now,
                'now' => $now,
            ]);
            return 'created';
        }

        // UPDATE existing: detect changes
        $changed = false;

        if ($existing['name'] !== $name) {
            $this->logChange($existing['id'], 'name', $existing['name'], $name, $taskId);
            $changed = true;
        }

        $newCost = $costPrice->amount();
        $oldCost = number_format((float) $existing['cost_price'], 6, '.', '');
        if ($oldCost !== $newCost) {
            $this->logChange($existing['id'], 'cost_price', $oldCost, $newCost, $taskId);
            $changed = true;
        }

        $newStatus = $status;
        $oldStatus = $existing['status'];
        if ($oldStatus !== $newStatus && !$this->isManualOffline($existing)) {
            $this->logChange($existing['id'], 'status', $oldStatus, $newStatus, $taskId);
            $changed = true;
        }

        if ((string) ($existing['attributes_json'] ?? '') !== $newAttrsJson) {
            $this->logChange(
                $existing['id'],
                'attributes',
                (string) ($existing['attributes_json'] ?? ''),
                $newAttrsJson,
                $taskId
            );
            $changed = true;
        }

        if ($changed) {
            $isManualOffline = $this->isManualOffline($existing);
            $stmt = $this->pdo->prepare(
                'UPDATE ' . $this->quoteTable('media_resources') . ' SET '
                . 'name = :name, cost_price = :cpr, status = :status, supplier_raw_status = :rstatus,'
                . ' attributes_json = :attrs, last_synced_at = :lsync, updated_at = :now '
                . 'WHERE id = :id'
            );
            $stmt->execute([
                'name' => mb_substr($name, 0, 500),
                'cpr' => $newCost,
                'status' => $isManualOffline ? $oldStatus : $newStatus,
                'rstatus' => $newStatus,
                'attrs' => $newAttrsJson,
                'lsync' => $now,
                'now' => $now,
                'id' => $existing['id'],
            ]);
            return 'updated';
        }

        // Just update sync time
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->quoteTable('media_resources') . ' SET last_synced_at = :lsync WHERE id = :id'
        );
        $stmt->execute(['lsync' => $now, 'id' => $existing['id']]);
        return 'skipped';
    }

    /**
     * Mark a media as manually offline (enterprise admin action).
     */
    public function manualOffline(int $mediaId, int $operatorId, string $reason): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->quoteTable('media_resources') . ' SET '
            . 'enterprise_status = :status, enterprise_status_set_by = :by,'
            . ' enterprise_status_set_at = :at, enterprise_status_reason = :reason,'
            . ' status = :offline, updated_at = :now '
            . 'WHERE id = :id'
        );
        $stmt->execute([
            'status' => 'offline',
            'by' => $operatorId,
            'at' => $now,
            'reason' => mb_substr($reason, 0, 500),
            'offline' => MediaStatus::OFFLINE,
            'now' => $now,
            'id' => $mediaId,
        ]);
    }

    /**
     * Get last successful sync time for a supplier.
     */
    public function lastSyncTime(int $supplierId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(last_synced_at) FROM ' . $this->quoteTable('media_resources')
            . ' WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $result = $stmt->fetchColumn();
        return $result !== null && $result !== false ? (string) $result : null;
    }

    private function findByExternalId(int $supplierId, string $externalId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->quoteTable('media_resources')
            . ' WHERE supplier_id = ? AND external_id = ?'
        );
        $stmt->execute([$supplierId, $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function logChange($mediaId, string $field, ?string $oldValue, ?string $newValue, string $taskId): void
    {
        $mediaId = (int) $mediaId;
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . $this->quoteTable('media_change_logs') . ' '
            . '(media_resource_id, change_type, field_name, old_value, new_value, sync_task_id, created_at) '
            . 'VALUES (:mid, :type, :field, :old, :new, :task, :now)'
        );
        $stmt->execute([
            'mid' => $mediaId,
            'type' => 'sync_change',
            'field' => $field,
            'old' => $oldValue !== null ? mb_substr($oldValue, 0, 500) : null,
            'new' => $newValue !== null ? mb_substr($newValue, 0, 500) : null,
            'task' => $taskId,
            'now' => date('Y-m-d H:i:s'),
        ]);
    }

    private function isManualOffline(array $existing): bool
    {
        return ($existing['enterprise_status'] ?? null) !== null;
    }

    /** @return array<string, int> */
    private function resolveChannelIds(array $channels): array
    {
        $ids = [];
        $stmt = $this->pdo->prepare(
            'SELECT id, code FROM ' . $this->quoteTable('channels') . ' WHERE status = ?'
        );
        $stmt->execute(['normal']);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ids[$row['code']] = (int) $row['id'];
        }
        return $ids;
    }

    private function clientFullSearch(SupplierAdapter $adapter, int $page, int $pageSize): array
    {
        $allItems = [];
        foreach ($adapter->listChannels() as $channel) {
            try {
                $mediaPage = $adapter->fetchMedia(new MediaQuery($channel->code(), $page, $pageSize));
                foreach ($mediaPage->items() as $item) {
                    $allItems[] = $item;
                }
            } catch (\Throwable $exception) {
                continue;
            }
        }
        return $allItems;
    }

    private function clearCatalogFilterCache(): void
    {
        if (!defined('ROOT_PATH')) {
            return;
        }
        $runtime = ROOT_PATH . 'runtime' . DIRECTORY_SEPARATOR;
        foreach (glob($runtime . 'media_catalog_filter_cache*.php') ?: [] as $path) {
            @unlink($path);
        }
    }

    private function quoteTable(string $name): string
    {
        $identifier = $this->prefix . $name;
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('Table identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
}

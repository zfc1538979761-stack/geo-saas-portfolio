<?php

declare(strict_types=1);

namespace app\common\supplier;

use app\common\supplier\exception\SupplierException;
use PDO;
use think\Log;

final class MediaSyncJob
{
    private MediaSyncService $syncService;
    private SupplierAdapterFactoryAdapter $adapterFactory;
    private PDO $pdo;
    private string $prefix;

    public function __construct(MediaSyncService $syncService, PDO $pdo, string $prefix)
    {
        $this->syncService = $syncService;
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }

    /**
     * Create a sync task record and return task ID.
     */
    public function createSyncTask(string $type, int $supplierId): string
    {
        $taskId = 'media_sync_' . $type . '_' . $supplierId . '_' . bin2hex(random_bytes(8));
        $now = date('Y-m-d H:i:s');

        // Use geo_async_jobs for task tracking
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . $this->quoteTable('geo_async_jobs') . ' '
            . '(type, biz_id, status, idempotency_key, created_at, updated_at) '
            . 'VALUES (:type, :biz, :status, :key, :now, :now)'
        );
        $stmt->execute([
            'type' => 'media_sync',
            'biz' => $taskId,
            'status' => 'pending',
            'key' => $taskId,
            'now' => $now,
        ]);

        return $taskId;
    }

    /**
     * Execute a full sync. This should be called by a queue worker.
     */
    public function executeFullSync(string $taskId, SupplierAdapter $adapter, int $supplierId): array
    {
        // Check idempotency - if another sync of same type for same supplier is running, skip
        if (!$this->acquireSyncLock('full', $supplierId)) {
            return ['status' => 'skipped', 'reason' => 'Another full sync is already running for this supplier.'];
        }

        try {
            // Mark task as running
            $this->updateTaskStatus($taskId, 'running');

            $result = $this->syncService->fullSync($adapter, $taskId, $supplierId);

            // Record result in task
            $this->updateTaskStatus($taskId, 'completed', json_encode($result, JSON_UNESCAPED_UNICODE));

            return array_merge(['status' => 'completed'], $result);
        } catch (SupplierException $exception) {
            $error = ['error' => $exception->getMessage(), 'category' => $exception->category()];
            $this->updateTaskStatus($taskId, 'failed', json_encode($error, JSON_UNESCAPED_UNICODE));
            Log::record('T09 media sync failed: ' . $exception->getMessage(), 'error');
            return array_merge(['status' => 'failed'], $error);
        } catch (\Throwable $exception) {
            $error = ['error' => $exception->getMessage(), 'category' => 'unknown'];
            $this->updateTaskStatus($taskId, 'failed', json_encode($error, JSON_UNESCAPED_UNICODE));
            Log::record('T09 media sync fatal: ' . $exception->getMessage(), 'error');
            return array_merge(['status' => 'failed'], $error);
        } finally {
            $this->releaseSyncLock('full', $supplierId);
        }
    }

    /**
     * Execute incremental sync.
     */
    public function executeIncrementalSync(string $taskId, SupplierAdapter $adapter, int $supplierId, ?string $sinceToken = null): array
    {
        if (!$this->acquireSyncLock('incremental', $supplierId)) {
            return ['status' => 'skipped', 'reason' => 'Another incremental sync is already running for this supplier.'];
        }

        try {
            $this->updateTaskStatus($taskId, 'running');

            $result = $this->syncService->incrementalSync($adapter, $taskId, $supplierId, $sinceToken);

            $this->updateTaskStatus($taskId, 'completed', json_encode($result, JSON_UNESCAPED_UNICODE));

            return array_merge(['status' => 'completed'], $result);
        } catch (SupplierException $exception) {
            $error = ['error' => $exception->getMessage(), 'category' => $exception->category()];
            $this->updateTaskStatus($taskId, 'failed', json_encode($error, JSON_UNESCAPED_UNICODE));
            return array_merge(['status' => 'failed'], $error);
        } catch (\Throwable $exception) {
            $error = ['error' => $exception->getMessage(), 'category' => 'unknown'];
            $this->updateTaskStatus($taskId, 'failed', json_encode($error, JSON_UNESCAPED_UNICODE));
            return array_merge(['status' => 'failed'], $error);
        } finally {
            $this->releaseSyncLock('incremental', $supplierId);
        }
    }

    private function acquireSyncLock(string $type, int $supplierId): bool
    {
        $lockKey = 'media_sync_lock_' . $type . '_' . $supplierId;
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . $this->quoteTable('geo_async_jobs') . ' '
                . '(type, biz_id, status, idempotency_key, created_at, updated_at) '
                . "VALUES ('media_sync_lock', :biz, 'running', :key, :now, :now)"
            );
            $stmt->execute([
                'biz' => $lockKey,
                'key' => $lockKey,
                'now' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    private function releaseSyncLock(string $type, int $supplierId): void
    {
        $lockKey = 'media_sync_lock_' . $type . '_' . $supplierId;
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM ' . $this->quoteTable('geo_async_jobs') . ' '
                . 'WHERE type = ? AND biz_id = ?'
            );
            $stmt->execute(['media_sync_lock', $lockKey]);
        } catch (\Throwable $exception) {
            // Best effort
        }
    }

    private function updateTaskStatus(string $taskId, string $status, ?string $result = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->quoteTable('geo_async_jobs') . ' SET '
            . 'status = :status, result = :result, updated_at = :now '
            . 'WHERE biz_id = :biz AND type = :type'
        );
        $stmt->execute([
            'status' => $status,
            'result' => $result,
            'now' => date('Y-m-d H:i:s'),
            'biz' => $taskId,
            'type' => 'media_sync',
        ]);
    }

    public function getTaskStatus(string $taskId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->quoteTable('geo_async_jobs') . ' '
            . 'WHERE biz_id = ? AND type = ?'
        );
        $stmt->execute([$taskId, 'media_sync']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function getSupplierId(string $code): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM ' . $this->quoteTable('suppliers') . ' WHERE code = ? AND status = ?'
        );
        $stmt->execute([$code, 'normal']);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    private function quoteTable(string $name): string
    {
        $identifier = $this->prefix . $name;
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new \RuntimeException('Table identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
}

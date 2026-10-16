<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\common\controller\Backend;
use app\common\supplier\MediaSyncJob;
use app\common\supplier\MediaSyncService;
use app\common\supplier\SupplierAdapterFactory;
use PDO;

class MediaSync extends Backend
{
    protected $noNeedLogin = [];
    protected $noNeedRight = ['*'];
    protected $layout = '';

    private PDO $pdo;
    private string $prefix;

    public function _initialize()
    {
        parent::_initialize();
        $this->pdo = \think\Db::connect()->getConnection()->getPdo();
        $this->prefix = \think\Db::getConfig('prefix');
    }

    /**
     * Internal: trigger full sync.
     * POST /admin/media_sync/full
     */
    public function full()
    {
        $config = config('geo_supplier');
        $environment = (string) ($config['environment'] ?? 'production');
        $supplierId = $this->getMediaMarketSupplierId();

        if ($supplierId === null) {
            return json(['code' => 500, 'msg' => 'MediaMarket supplier not found.', 'time' => time()]);
        }

        try {
            $adapter = SupplierAdapterFactory::create($config);
            $syncService = new MediaSyncService($this->pdo, $this->prefix);
            $syncJob = new MediaSyncJob($syncService, $this->pdo, $this->prefix);

            $taskId = $syncJob->createSyncTask('full', $supplierId);
            $result = $syncJob->executeFullSync($taskId, $adapter, $supplierId);

            return json([
                'code' => $result['status'] === 'completed' ? 200 : 500,
                'msg' => $result['status'] === 'completed' ? 'Full sync completed.' : 'Full sync failed.',
                'data' => $result,
                'time' => time(),
            ]);
        } catch (\Throwable $exception) {
            return json([
                'code' => 500,
                'msg' => 'Full sync error: ' . $exception->getMessage(),
                'time' => time(),
            ]);
        }
    }

    /**
     * Internal: trigger incremental sync.
     * POST /admin/media_sync/incremental
     */
    public function incremental()
    {
        $config = config('geo_supplier');
        $supplierId = $this->getMediaMarketSupplierId();

        if ($supplierId === null) {
            return json(['code' => 500, 'msg' => 'MediaMarket supplier not found.', 'time' => time()]);
        }

        try {
            $adapter = SupplierAdapterFactory::create($config);
            $syncService = new MediaSyncService($this->pdo, $this->prefix);
            $syncJob = new MediaSyncJob($syncService, $this->pdo, $this->prefix);

            $taskId = $syncJob->createSyncTask('incremental', $supplierId);
            $sinceToken = $this->request->param('since_token', null);
            $result = $syncJob->executeIncrementalSync($taskId, $adapter, $supplierId, $sinceToken);

            return json([
                'code' => $result['status'] === 'completed' ? 200 : 500,
                'msg' => $result['status'] === 'completed' ? 'Incremental sync completed.' : 'Incremental sync failed.',
                'data' => $result,
                'time' => time(),
            ]);
        } catch (\Throwable $exception) {
            return json([
                'code' => 500,
                'msg' => 'Incremental sync error: ' . $exception->getMessage(),
                'time' => time(),
            ]);
        }
    }

    /**
     * Internal: get sync task status.
     * GET /admin/media_sync/status?task_id=xxx
     */
    public function status()
    {
        $taskId = trim((string) $this->request->param('task_id', ''));
        if ($taskId === '') {
            return json(['code' => 400, 'msg' => 'Missing task_id.', 'time' => time()]);
        }

        $syncJob = new MediaSyncJob(
            new MediaSyncService($this->pdo, $this->prefix),
            $this->pdo,
            $this->prefix
        );

        $task = $syncJob->getTaskStatus($taskId);
        if ($task === null) {
            return json(['code' => 404, 'msg' => 'Task not found.', 'time' => time()]);
        }

        return json([
            'code' => 200,
            'msg' => 'OK',
            'data' => [
                'task_id' => $task['biz_id'] ?? '',
                'status' => $task['status'] ?? '',
                'result' => json_decode((string) ($task['result'] ?? '{}'), true),
                'created_at' => $task['created_at'] ?? '',
                'updated_at' => $task['updated_at'] ?? '',
            ],
            'time' => time(),
        ]);
    }

    /**
     * Internal: list media with all details and raw attributes.
     * GET /admin/media_sync/media_list
     */
    public function media_list()
    {
        $page = max(1, $this->request->param('page', 1, 'intval'));
        $perPage = min(max(1, $this->request->param('per_page', 20, 'intval')), 100);

        $offset = ($page - 1) * $perPage;

        $countSql = 'SELECT COUNT(*) FROM ' . $this->quoteTable('media_resources');
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT mr.*, c.code AS channel_code, c.name AS channel_name'
            . ' FROM ' . $this->quoteTable('media_resources') . ' mr'
            . ' LEFT JOIN ' . $this->quoteTable('channels') . ' c ON c.id = mr.channel_id'
            . ' ORDER BY mr.id DESC'
            . ' LIMIT ' . $offset . ', ' . $perPage
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'supplier_id' => (int) $row['supplier_id'],
                'external_id' => $row['external_id'],
                'channel_code' => $row['channel_code'] ?? '',
                'name' => $row['name'],
                'cost_price' => $row['cost_price'],
                'status' => $row['status'],
                'supplier_raw_status' => $row['supplier_raw_status'],
                'enterprise_status' => $row['enterprise_status'],
                'attributes_json' => json_decode((string) ($row['attributes_json'] ?? '{}'), true),
                'last_synced_at' => $row['last_synced_at'],
                'last_sync_error' => $row['last_sync_error'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }

        return json([
            'code' => 200,
            'msg' => 'OK',
            'data' => ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage],
            'time' => time(),
        ]);
    }

    /**
     * Internal: manual offline a media.
     * POST /admin/media_sync/offline
     */
    public function offline()
    {
        $mediaId = $this->request->param('media_id', 0, 'intval');
        $reason = trim((string) $this->request->param('reason', ''));

        if ($mediaId <= 0) {
            return json(['code' => 400, 'msg' => 'Invalid media_id.', 'time' => time()]);
        }
        if ($reason === '') {
            return json(['code' => 400, 'msg' => 'Reason is required.', 'time' => time()]);
        }

        $operatorId = (int) ($this->auth->id ?? 0);
        $syncService = new MediaSyncService($this->pdo, $this->prefix);
        $syncService->manualOffline($mediaId, $operatorId, $reason);

        return json(['code' => 200, 'msg' => 'Media marked as offline.', 'time' => time()]);
    }

    /**
     * Internal: sync status overview.
     * GET /admin/media_sync/overview
     */
    public function overview()
    {
        $supplierId = $this->getMediaMarketSupplierId();
        $lastSync = null;

        if ($supplierId !== null) {
            $syncService = new MediaSyncService($this->pdo, $this->prefix);
            $lastSync = $syncService->lastSyncTime($supplierId);
        }

        // Count by status
        $stmt = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS cnt FROM ' . $this->quoteTable('media_resources')
            . ' GROUP BY status'
        );
        $stmt->execute();
        $statusCounts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $statusCounts[$row['status']] = (int) $row['cnt'];
        }

        // Count by channel
        $stmt = $this->pdo->prepare(
            'SELECT c.code, c.name, COUNT(mr.id) AS cnt'
            . ' FROM ' . $this->quoteTable('channels') . ' c'
            . ' LEFT JOIN ' . $this->quoteTable('media_resources') . ' mr ON mr.channel_id = c.id'
            . ' GROUP BY c.id, c.code, c.name'
        );
        $stmt->execute();
        $channelCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Total
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $this->quoteTable('media_resources'));
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();

        return json([
            'code' => 200,
            'msg' => 'OK',
            'data' => [
                'total_media' => $total,
                'by_status' => $statusCounts,
                'by_channel' => $channelCounts,
                'last_sync_time' => $lastSync,
                'supplier_id' => $supplierId,
            ],
            'time' => time(),
        ]);
    }

    private function getMediaMarketSupplierId(): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM ' . $this->quoteTable('suppliers') . ' WHERE code = ? AND status = ?'
        );
        $stmt->execute(['media_market', 'normal']);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    private function quoteTable(string $name): string
    {
        return '`' . $this->prefix . $name . '`';
    }
}

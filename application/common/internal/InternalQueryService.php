<?php

declare(strict_types=1);

namespace app\common\internal;

use app\common\ai\task\AiTaskRuntimeFactory;
use app\common\billing\BillingService;
use app\common\foundation\SensitiveDataRedactor;
use app\common\operations\MonitoringService;
use app\common\publishing\PublishCallbackService;
use app\common\publishing\PublishOrderService;
use app\common\publishing\PublishSubmitJob;
use app\common\publishing\PublishSyncJob;
use app\common\supplier\MediaSyncJob;
use app\common\supplier\MediaSyncService;
use app\common\supplier\SupplierAdapterFactory;
use app\common\tenant\InternalAdminIdentity;
use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantBypassAuthorizer;
use app\common\tenant\TenantContext;
use PDO;

final class InternalQueryService
{
    private PDO $pdo;
    private string $prefix;

    public function __construct(PDO $pdo, string $prefix)
    {
        if (preg_match('/^[A-Za-z0-9_]*$/D', $prefix) !== 1) {
            throw new \InvalidArgumentException('Internal query table prefix is invalid.');
        }
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }

    public function summary(): array
    {
        return [
            'companies' => (int) $this->count($this->q('companies')),
            'failed_jobs' => (int) $this->countWhere($this->q('geo_async_jobs'), "`status`='failed'"),
            'manual_orders' => (int) $this->countWhere($this->q('publish_orders'), "`status`='manual_review'"),
            'audit_logs' => (int) $this->count($this->q('audit_logs')),
            'media_resources' => (int) $this->count($this->q('media_resources')),
        ];
    }

    public function monitorHealth(): array
    {
        return (new MonitoringService($this->pdo, $this->prefix))->health();
    }

    public function runMonitorCheck(int $actorId, string $ip): array
    {
        $result = (new MonitoringService($this->pdo, $this->prefix))->runCheck(true);
        $this->auditDirect($actorId, 'internal_monitor_checked', null, 'monitor', null, $ip, [
            'overall_status' => $result['health']['overall_status'],
            'alert_count' => count($result['alerts']),
            'snapshot_id' => $result['snapshot_id'],
        ]);
        return $result;
    }

    public function alertEvents(int $page, int $perPage, ?string $status = null): array
    {
        return (new MonitoringService($this->pdo, $this->prefix))->alertEvents($page, $perPage, $status);
    }

    public function acknowledgeAlert(int $id, int $actorId, string $reason, string $ip): array
    {
        $result = (new MonitoringService($this->pdo, $this->prefix))->acknowledgeAlert($id, $actorId, $reason, $ip);
        $this->auditDirect($actorId, 'internal_alert_acknowledged', null, 'alert_event', (string) $id, $ip, [
            'reason' => $reason,
        ]);
        return $result;
    }

    public function backupRecords(int $page, int $perPage): array
    {
        return (new MonitoringService($this->pdo, $this->prefix))->backupRecords($page, $perPage);
    }

    public function webhookEvents(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $countStmt = $this->pdo->query('SELECT COUNT(*) FROM ' . $this->q('webhook_events'));
        $total = (int) $countStmt->fetchColumn();
        $stmt = $this->pdo->prepare(
            'SELECT `id`,`company_id`,`supplier_code`,`event_id`,`status`,`processing_result`,'
            . '`processing_error`,`received_at`,`processed_at` FROM ' . $this->q('webhook_events')
            . ' ORDER BY `id` DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function replayWebhookEvent(int $webhookId, int $actorId, string $reason, string $ip): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->q('webhook_events') . ' WHERE `id`=:id LIMIT 1');
        $stmt->execute(['id' => $webhookId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($event)) {
            throw new \RuntimeException('Webhook event was not found.');
        }
        $config = (array) \think\Config::get('geo_supplier');
        if ($config === []) {
            $config = (array) require APP_PATH . 'extra' . DIRECTORY_SEPARATOR . 'geo_supplier.php';
        }
        $adapter = SupplierAdapterFactory::create($config);
        $audit = new PdoAuditLogger($this->pdo, $this->prefix);
        $billing = new BillingService($this->pdo, $audit, $this->prefix);
        $orderService = new PublishOrderService($this->pdo, $billing, $this->prefix);
        $syncService = new \app\common\publishing\PublishSyncService($this->pdo, $orderService, $this->prefix);
        $callbackService = new PublishCallbackService($this->pdo, $syncService, $orderService, $this->prefix);
        $result = $callbackService->replayEvent($webhookId, $adapter);
        $this->auditDirect($actorId, 'internal_webhook_replayed', (int) $event['company_id'], 'webhook_event', (string) $webhookId, $ip, [
            'supplier_code' => $event['supplier_code'],
            'event_id' => $event['event_id'],
            'replay_status' => $result['status'],
            'reason' => $reason,
        ]);
        return $result;
    }

    public function aiProviders(): array
    {
        $stmt = $this->pdo->query('SELECT `code`,`display_name`,`status`,`updated_at` FROM ' . $this->q('ai_providers') . ' ORDER BY `id` ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function mediaSyncOverview(): array
    {
        $supplierId = $this->supplierId('media_market');
        $lastSync = null;
        if ($supplierId !== null) {
            $lastSync = (new MediaSyncService($this->pdo, $this->prefix))->lastSyncTime($supplierId);
        }
        $stmt = $this->pdo->prepare(
            'SELECT `biz_id`,`status`,`result`,`created_at`,`updated_at`'
            . ' FROM ' . $this->q('geo_async_jobs')
            . " WHERE `type`='media_sync' ORDER BY `id` DESC LIMIT 20"
        );
        $stmt->execute();
        $jobs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $jobs[] = [
                'task_id' => $row['biz_id'],
                'status' => $row['status'],
                'result' => json_decode((string) $row['result'], true),
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }
        return [
            'supplier_id' => $supplierId,
            'last_sync_time' => $lastSync,
            'recent_tasks' => $jobs,
        ];
    }

    public function triggerMediaSync(string $type, int $actorId, string $reason, string $ip): array
    {
        $type = strtolower(trim($type));
        if (!in_array($type, ['full', 'incremental'], true)) {
            throw new \InvalidArgumentException('Media sync type is invalid.');
        }
        $supplierId = $this->supplierId('media_market');
        if ($supplierId === null) {
            throw new \RuntimeException('MediaMarket supplier is not enabled.');
        }
        $config = (array) \think\Config::get('geo_supplier');
        if ($config === []) {
            $config = (array) require APP_PATH . 'extra' . DIRECTORY_SEPARATOR . 'geo_supplier.php';
        }
        $adapter = SupplierAdapterFactory::create($config);
        $syncService = new MediaSyncService($this->pdo, $this->prefix);
        $job = new MediaSyncJob($syncService, $this->pdo, $this->prefix);
        $taskId = $job->createSyncTask($type, $supplierId);
        if ($type === 'full') {
            $result = $job->executeFullSync($taskId, $adapter, $supplierId);
        } else {
            $result = $job->executeIncrementalSync($taskId, $adapter, $supplierId);
        }
        $this->auditDirect($actorId, 'internal_media_sync_triggered', null, 'media_sync', $taskId, $ip, [
            'type' => $type,
            'reason' => $reason,
            'result_status' => $result['status'] ?? 'unknown',
        ]);
        return array_merge(['task_id' => $taskId], $result);
    }

    public function companies(int $page, int $perPage, string $search = ''): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $where = '1=1';
        $params = [];
        if ($search !== '') {
            $where .= ' AND `name` LIKE :search';
            $params['search'] = '%' . $search . '%';
        }
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $this->q('companies') . ' WHERE ' . $where);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $stmt = $this->pdo->prepare(
            'SELECT `id`,`name`,`status`,`createtime`,`updatetime` FROM ' . $this->q('companies')
            . ' WHERE ' . $where . ' ORDER BY `id` DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function setCompanyStatus(int $companyId, string $status, int $actorId, string $reason, string $ip): void
    {
        if (!in_array($status, ['normal', 'disabled'], true)) {
            throw new \InvalidArgumentException('Company status is invalid.');
        }
        $stmt = $this->pdo->prepare('UPDATE ' . $this->q('companies') . ' SET `status`=:status,`updatetime`=:now WHERE `id`=:id');
        $stmt->execute(['status' => $status, 'now' => time(), 'id' => $companyId]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Company was not found or status was unchanged.');
        }
        $this->auditDirect($actorId, 'internal_company_status_changed', $companyId, 'company', (string) $companyId, $ip, [
            'status' => $status,
            'reason' => $reason,
        ]);
    }

    public function walletCompanies(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $stmt = $this->pdo->prepare(
            'SELECT c.`id` AS company_id,c.`name` AS company_name,c.`status` AS company_status,'
            . ' w.`available_balance`,w.`frozen_balance`,w.`currency`'
            . ' FROM ' . $this->q('companies') . ' c'
            . ' LEFT JOIN ' . $this->q('wallets') . ' w ON w.`company_id`=c.`id`'
            . ' ORDER BY c.`id` DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function failedJobs(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $countStmt = $this->pdo->query('SELECT COUNT(*) FROM ' . $this->q('geo_async_jobs') . " WHERE `status`='failed'");
        $total = (int) $countStmt->fetchColumn();
        $stmt = $this->pdo->prepare(
            'SELECT `id`,`company_id`,`queue`,`job_type`,`status`,`attempts`,`max_attempts`,'
            . '`last_error_code`,`last_error_message`,`failed_at`,`created_at`,`updated_at`'
            . ' FROM ' . $this->q('geo_async_jobs')
            . " WHERE `status`='failed' ORDER BY `updated_at` DESC LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function replayJob(int $jobId, int $actorId, string $reason, string $ip): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->q('geo_async_jobs') . ' WHERE `id`=:id LIMIT 1');
        $stmt->execute(['id' => $jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($job)) {
            throw new \RuntimeException('Job was not found.');
        }
        if ($job['status'] !== 'failed') {
            throw new \RuntimeException('Only failed jobs can be replayed.');
        }
        if ($job['job_type'] === 'ai_article_generation') {
            $identity = new InternalAdminIdentity($actorId, [TenantBypassAuthorizer::PERMISSION]);
            $audit = new PdoAuditLogger($this->pdo, $this->prefix);
            $context = (new TenantBypassAuthorizer($audit))->authorize($identity, $reason, $ip);
            $result = AiTaskRuntimeFactory::service()->retry($jobId, $context, $ip);
        } elseif (in_array($job['job_type'], ['publish_sync', 'publish_submit'], true)) {
            $payload = json_decode((string) $job['payload_json'], true);
            if (!is_array($payload)) {
                throw new \RuntimeException('This job payload cannot be replayed safely.');
            }
            if ((int) ($payload['company_id'] ?? 0) < 1 || (int) ($payload['order_id'] ?? 0) < 1) {
                throw new \RuntimeException('This job payload is missing company/order identifiers.');
            }
            if ($job['job_type'] === 'publish_sync') {
                $result = PublishSyncJob::runInline($this->pdo, $payload, $this->prefix);
            } else {
                PublishSubmitJob::runInline($this->pdo, $payload, $this->prefix);
                $result = ['status' => 'replayed'];
            }
        } elseif ($job['job_type'] === 'media_sync') {
            $result = $this->replayMediaSync((string) $job['biz_id'], $actorId, $reason, $ip);
        } else {
            throw new \RuntimeException('This job type cannot be replayed from the internal backend.');
        }
        $this->auditDirect($actorId, 'internal_job_replayed', $job['company_id'] !== null ? (int) $job['company_id'] : null, 'job', (string) $jobId, $ip, [
            'job_type' => $job['job_type'],
            'reason' => $reason,
        ]);
        return ['job_id' => $jobId, 'result' => $result];
    }

    public function orders(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $countStmt = $this->pdo->query('SELECT COUNT(*) FROM ' . $this->q('publish_orders') . " WHERE `status`='manual_review'");
        $total = (int) $countStmt->fetchColumn();
        $stmt = $this->pdo->prepare(
            'SELECT o.`id`,o.`order_no`,o.`company_id`,c.`name` AS company_name,o.`status`,'
            . 'o.`total_amount`,o.`frozen_amount`,o.`created_at`,o.`updated_at`'
            . ' FROM ' . $this->q('publish_orders') . ' o'
            . ' LEFT JOIN ' . $this->q('companies') . ' c ON c.`id`=o.`company_id`'
            . " WHERE o.`status`='manual_review' ORDER BY o.`updated_at` DESC LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function reprocessOrder(int $orderId, string $action, int $actorId, string $reason, string $ip): array
    {
        $action = strtolower(trim($action));
        if (!in_array($action, ['sync', 'submit', 'cancel'], true)) {
            throw new \InvalidArgumentException('Order reprocess action is invalid.');
        }
        $orderStmt = $this->pdo->prepare('SELECT `company_id` FROM ' . $this->q('publish_orders') . ' WHERE `id`=:id LIMIT 1');
        $orderStmt->execute(['id' => $orderId]);
        $companyId = (int) $orderStmt->fetchColumn();
        if ($companyId < 1) {
            throw new \RuntimeException('Order was not found.');
        }
        $audit = new PdoAuditLogger($this->pdo, $this->prefix);
        $billing = new BillingService($this->pdo, $audit, $this->prefix);
        $orderService = new PublishOrderService($this->pdo, $billing, $this->prefix);
        if ($action === 'sync') {
            $result = PublishSyncJob::runInline($this->pdo, ['company_id' => $companyId, 'order_id' => $orderId], $this->prefix);
        } elseif ($action === 'submit') {
            PublishSubmitJob::runInline($this->pdo, ['company_id' => $companyId, 'order_id' => $orderId], $this->prefix);
            $result = $orderService->getDetail($companyId, $orderId)['order'];
        } else {
            $result = $orderService->cancel($companyId, $orderId, $actorId, $ip);
        }
        $this->auditDirect($actorId, 'internal_order_reprocessed', $companyId, 'publish_order', (string) $orderId, $ip, [
            'action' => $action,
            'reason' => $reason,
        ]);
        return ['action' => $action, 'result' => $result];
    }

    public function auditLogs(int $page, int $perPage, ?int $companyId = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $where = '1=1';
        $params = [];
        if ($companyId !== null) {
            $where .= ' AND a.`company_id`=:company_id';
            $params['company_id'] = $companyId;
        }
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $this->q('audit_logs') . ' a WHERE ' . $where);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $stmt = $this->pdo->prepare(
            'SELECT a.`id`,a.`actor_id`,a.`actor_type`,a.`company_id`,a.`action`,a.`target_type`,'
            . 'a.`target_id`,a.`ip_address`,a.`detail_json`,a.`created_at`,c.`name` AS company_name'
            . ' FROM ' . $this->q('audit_logs') . ' a'
            . ' LEFT JOIN ' . $this->q('companies') . ' c ON c.`id`=a.`company_id`'
            . ' WHERE ' . $where . ' ORDER BY a.`id` DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['detail'] = json_decode((string) $row['detail_json'], true);
            unset($row['detail_json']);
            $items[] = $row;
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function logFiles(): array
    {
        $dir = $this->logDirectory();
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.log') ?: [];
        $items = [];
        foreach ($files as $file) {
            $items[] = [
                'name' => basename($file),
                'size' => filesize($file),
                'updated_at' => date('Y-m-d H:i:s', filemtime($file)),
            ];
        }
        usort($items, static fn(array $a, array $b): int => strcmp($b['updated_at'], $a['updated_at']));
        return $items;
    }

    public function logTail(string $fileName, int $lines = 200): array
    {
        if (preg_match('/^[A-Za-z0-9._-]+\.log$/D', $fileName) !== 1) {
            throw new \InvalidArgumentException('Log file name is invalid.');
        }
        $dir = $this->logDirectory();
        $path = $dir . DIRECTORY_SEPARATOR . $fileName;
        if (!is_file($path)) {
            throw new \RuntimeException('Log file was not found.');
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Log file could not be read.');
        }
        $all = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $all = array_slice($all, max(0, count($all) - max(1, min(500, $lines))));
        return ['name' => $fileName, 'lines' => array_map(
            static fn(string $line): string => (string) SensitiveDataRedactor::redact($line),
            $all
        )];
    }

    private function replayMediaSync(string $taskId, int $actorId, string $reason, string $ip): array
    {
        $type = null;
        if (strpos($taskId, 'media_sync_full_') === 0) {
            $type = 'full';
        } elseif (strpos($taskId, 'media_sync_incremental_') === 0) {
            $type = 'incremental';
        }
        if ($type === null) {
            throw new \RuntimeException('Media sync task type is invalid.');
        }
        $supplierId = $this->supplierId('media_market');
        if ($supplierId === null) {
            throw new \RuntimeException('MediaMarket supplier is not enabled.');
        }
        $config = (array) \think\Config::get('geo_supplier');
        if ($config === []) {
            $config = (array) require APP_PATH . 'extra' . DIRECTORY_SEPARATOR . 'geo_supplier.php';
        }
        $adapter = SupplierAdapterFactory::create($config);
        $service = new MediaSyncService($this->pdo, $this->prefix);
        $job = new MediaSyncJob($service, $this->pdo, $this->prefix);
        $newTaskId = $job->createSyncTask($type, $supplierId);
        if ($type === 'full') {
            return $job->executeFullSync($newTaskId, $adapter, $supplierId);
        }
        return $job->executeIncrementalSync($newTaskId, $adapter, $supplierId);
    }

    private function supplierId(string $code): ?int
    {
        $stmt = $this->pdo->prepare('SELECT `id` FROM ' . $this->q('suppliers') . ' WHERE `code`=:code AND `status`=\'normal\' LIMIT 1');
        $stmt->execute(['code' => $code]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    private function auditDirect(
        int $actorId,
        string $action,
        ?int $companyId,
        string $targetType,
        ?string $targetId,
        string $ip,
        array $detail
    ): void {
        (new InternalAuditWriter($this->pdo, $this->prefix))->record(
            $actorId,
            $action,
            $companyId,
            $targetType,
            $targetId,
            $ip,
            $detail
        );
    }

    private function count(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private function countWhere(string $table, string $where): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where)->fetchColumn();
    }

    private function q(string $name): string
    {
        $identifier = $this->prefix . $name;
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new \RuntimeException('Internal query table identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }

    private function logDirectory(): string
    {
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'log';
    }
}

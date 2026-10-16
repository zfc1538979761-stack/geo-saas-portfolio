<?php

declare(strict_types=1);

namespace app\common\operations;

use PDO;
use RuntimeException;
use Throwable;

final class MonitoringService
{
    private const ORDER_STATUSES = [
        'funds_frozen', 'queued', 'submitted', 'processing', 'published', 'rejected',
        'cancelled', 'after_sale', 'refunded', 'manual_review', 'partially_completed',
    ];

    private const AI_ERROR_CATEGORIES = [
        'rate_limited', 'rate_limit', 'timeout', 'network_error', 'provider_server_error', 'server',
    ];

    private PDO $pdo;
    private string $prefix;
    private array $config;
    private $databaseProbe;
    private $redisProbe;
    private $storageProbe;
    private $diskProbe;
    private $aiProbe;
    private $supplierProbe;
    private $clock;

    public function __construct(
        PDO $pdo,
        string $prefix,
        array $config = [],
        ?callable $databaseProbe = null,
        ?callable $redisProbe = null,
        ?callable $storageProbe = null,
        ?callable $diskProbe = null,
        ?callable $aiProbe = null,
        ?callable $supplierProbe = null,
        ?callable $clock = null
    ) {
        if (preg_match('/^[A-Za-z0-9_]*$/D', $prefix) !== 1) {
            throw new RuntimeException('Monitoring table prefix is invalid.');
        }
        $this->pdo = $pdo;
        $this->prefix = $prefix;
        $this->config = $config === [] ? $this->defaultConfig() : $config;
        $this->databaseProbe = $databaseProbe;
        $this->redisProbe = $redisProbe;
        $this->storageProbe = $storageProbe;
        $this->diskProbe = $diskProbe;
        $this->aiProbe = $aiProbe;
        $this->supplierProbe = $supplierProbe;
        $this->clock = $clock ?: static fn(): int => time();
    }

    public function health(): array
    {
        $database = $this->databaseStatus();
        $redis = $this->redisStatus();
        $ai = $this->aiStatus();
        $supplier = $this->supplierStatus();
        $queue = $this->queueStatus();
        $orders = $this->orderStatus();
        $financial = $this->financialStatus();
        $disk = $this->diskStatus();
        $storage = $this->storageStatus();
        $backup = $this->backupStatus();

        $degraded = in_array(true, [
            $ai['status'] === 'degraded',
            $supplier['status'] === 'degraded',
            $queue['failed'] > 0,
            $orders['stale_count'] > 0,
            $financial['anomaly_count'] > 0,
            $disk['status'] === 'degraded',
            $storage['status'] === 'degraded',
            $backup['status'] === 'degraded',
        ], true);

        if ($database['status'] === 'unavailable' || $redis['status'] === 'unavailable') {
            $overall = 'unavailable';
        } elseif ($degraded) {
            $overall = 'degraded';
        } else {
            $overall = 'healthy';
        }

        return [
            'checked_at' => $this->nowDate(),
            'overall_status' => $overall,
            'database' => $database,
            'redis' => $redis,
            'ai_provider' => $ai,
            'supplier' => $supplier,
            'queue' => $queue,
            'orders' => $orders,
            'financial' => $financial,
            'disk' => $disk,
            'storage' => $storage,
            'backup' => $backup,
        ];
    }

    public function runCheck(bool $persist = true): array
    {
        $health = $this->health();
        $alerts = $this->buildAlerts($health);
        $snapshotId = null;
        if ($persist) {
            $this->upsertAlerts($alerts);
            $this->resolveMissing(array_column($alerts, 'rule_key'));
            $snapshotId = $this->writeSnapshot($health);
        }
        return [
            'checked_at' => $health['checked_at'],
            'health' => $health,
            'alerts' => $alerts,
            'snapshot_id' => $snapshotId,
        ];
    }

    public function alertEvents(int $page = 1, int $perPage = 50, ?string $status = null): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $where = '1=1';
        $params = [];
        if ($status !== null && $status !== '') {
            $where .= ' AND `status`=:status';
            $params['status'] = $status;
        }
        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . $this->q('geo_alert_events') . ' WHERE ' . $where
        );
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $count = (int) $countStmt->fetchColumn();
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->q('geo_alert_events')
            . ' WHERE ' . $where . ' ORDER BY `last_seen_at` DESC, `id` DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['metadata'] = $this->decodeJson($row['metadata_json']);
            unset($row['metadata_json']);
            $items[] = $row;
        }
        return ['items' => $items, 'total' => $count, 'page' => $page, 'per_page' => $perPage];
    }

    public function acknowledgeAlert(int $id, int $actorId, string $reason, string $ip): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->q('geo_alert_events') . ' WHERE `id`=:id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Alert event was not found.');
        }
        $now = $this->nowDate();
        $upd = $this->pdo->prepare(
            'UPDATE ' . $this->q('geo_alert_events')
            . ' SET `status`=:status,`acknowledged_at`=:at,`acknowledged_by`=:by,'
            . '`ack_reason`=:reason,`updated_at`=:ut WHERE `id`=:id'
        );
        $upd->execute([
            'status' => $row['status'] === 'resolved' ? 'resolved' : 'acknowledged',
            'at' => $now,
            'by' => $actorId,
            'reason' => substr($reason, 0, 500),
            'ut' => $now,
            'id' => $id,
        ]);
        $row['status'] = $upd->rowCount() === 1 && $row['status'] !== 'resolved' ? 'acknowledged' : $row['status'];
        return $row;
    }

    public function backupRecords(int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $this->q('geo_backup_records'))->fetchColumn();
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . $this->q('geo_backup_records')
            . ' ORDER BY `id` DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['row_counts'] = $this->decodeJson($row['row_counts_json']);
            unset($row['row_counts_json']);
            $items[] = $row;
        }
        return ['items' => $items, 'total' => $count, 'page' => $page, 'per_page' => $perPage];
    }

    public function recordBackup(string $operation, string $status, array $meta = []): int
    {
        $operation = in_array($operation, ['backup', 'restore'], true) ? $operation : 'backup';
        $status = in_array($status, ['completed', 'failed', 'running'], true) ? $status : 'failed';
        $now = $this->nowDate();
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . $this->q('geo_backup_records')
            . ' (`operation`,`status`,`source_database`,`target_database`,`file_path`,`checksum`,'
            . '`row_counts_json`,`error_message`,`started_at`,`completed_at`)'
            . ' VALUES (:op,:st,:src,:dst,:file,:sum,:rows,:err,:started,:completed)'
        );
        $stmt->execute([
            'op' => $operation,
            'st' => $status,
            'src' => $this->nullableString($meta['source_database'] ?? null, 191),
            'dst' => $this->nullableString($meta['target_database'] ?? null, 191),
            'file' => $this->nullableString($meta['file_path'] ?? null, 500),
            'sum' => $this->nullableString($meta['checksum'] ?? null, 64),
            'rows' => isset($meta['row_counts']) ? json_encode($meta['row_counts'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            'err' => $this->nullableString($meta['error_message'] ?? null, 500),
            'started' => $this->nullableString($meta['started_at'] ?? $now, 19),
            'completed' => $status === 'running' ? null : $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function databaseStatus(): array
    {
        if (($this->config['probe']['database'] ?? true) === false) {
            return ['status' => 'configured', 'latency_ms' => null];
        }
        $started = microtime(true);
        try {
            if ($this->databaseProbe !== null) {
                call_user_func($this->databaseProbe);
            } else {
                $this->pdo->query('SELECT 1')->fetchColumn();
            }
            return ['status' => 'ok', 'latency_ms' => $this->latencyMs($started)];
        } catch (Throwable $exception) {
            return [
                'status' => 'unavailable',
                'latency_ms' => null,
                'error_class' => str_replace('\\', '.', get_class($exception)),
                'error_code' => $this->safeCode($exception),
            ];
        }
    }

    private function redisStatus(): array
    {
        if (($this->config['probe']['redis'] ?? true) === false) {
            return ['status' => 'configured', 'latency_ms' => null];
        }
        $started = microtime(true);
        try {
            if ($this->redisProbe !== null) {
                call_user_func($this->redisProbe);
                return ['status' => 'ok', 'latency_ms' => $this->latencyMs($started)];
            }
            $queue = (array) \think\Config::get('queue');
            if ($queue === []) {
                $queue = require APP_PATH . 'extra' . DIRECTORY_SEPARATOR . 'queue.php';
            }
            $redis = new \Redis();
            $connected = $redis->connect(
                (string) ($queue['host'] ?? '127.0.0.1'),
                (int) ($queue['port'] ?? 6379),
                (float) ($queue['timeout'] ?? 2)
            );
            if (!$connected) {
                throw new RuntimeException('Redis dependency unavailable.');
            }
            $password = (string) ($queue['password'] ?? '');
            if ($password !== '' && !$redis->auth($password)) {
                throw new RuntimeException('Redis dependency unavailable.');
            }
            if (!$redis->select((int) ($queue['select'] ?? 0)) || !$redis->ping()) {
                throw new RuntimeException('Redis dependency unavailable.');
            }
            $redis->close();
            return ['status' => 'ok', 'latency_ms' => $this->latencyMs($started)];
        } catch (Throwable $exception) {
            return [
                'status' => 'unavailable',
                'latency_ms' => null,
                'error_class' => str_replace('\\', '.', get_class($exception)),
                'error_code' => $this->safeCode($exception),
            ];
        }
    }

    private function aiStatus(): array
    {
        if ($this->aiProbe !== null) {
            $probe = call_user_func($this->aiProbe);
            $degraded = $probe === false || $probe === 'degraded';
            return [
                'status' => $degraded ? 'degraded' : 'ok',
                'providers' => [],
                'consecutive_failures' => $degraded ? 1 : 0,
                'recent_failures' => $degraded ? 1 : 0,
                'recent_rate_limited' => $degraded ? 1 : 0,
                'recent_timeouts' => 0,
                'recent_successes' => $degraded ? 0 : 1,
                'configured_provider' => 'injected',
            ];
        }
        $since = $this->sinceDate((int) ($this->config['period_seconds'] ?? 300));
        $providers = [];
        try {
            $stmt = $this->pdo->query('SELECT `code`,`status`,`updated_at` FROM ' . $this->q('ai_providers') . ' ORDER BY `id` ASC');
            $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            return ['status' => 'unknown', 'providers' => [], 'error' => $this->safeError($exception)];
        }

        $stats = ['failed' => 0, 'rate_limited' => 0, 'timeout' => 0, 'succeeded' => 0];
        try {
            $stmt = $this->pdo->prepare(
                'SELECT `status`,`error_category` FROM ' . $this->q('ai_calls')
                . ' WHERE `started_at`>=:since'
            );
            $stmt->execute(['since' => $since]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $category = (string) ($row['error_category'] ?? '');
                if ($row['status'] === 'succeeded') {
                    $stats['succeeded']++;
                } elseif ($row['status'] === 'failed') {
                    $stats['failed']++;
                    if (in_array($category, ['rate_limited', 'rate_limit'], true)) {
                        $stats['rate_limited']++;
                    }
                    if (in_array($category, ['timeout', 'network_error', 'provider_server_error', 'server'], true)) {
                        $stats['timeout']++;
                    }
                }
            }
        } catch (Throwable $exception) {
            $stats['error'] = $this->safeError($exception);
        }

        $consecutive = 0;
        try {
            $stmt = $this->pdo->query(
                'SELECT `status`,`last_error_code` FROM ' . $this->q('geo_async_jobs')
                . " WHERE `job_type`='ai_article_generation' ORDER BY `id` DESC LIMIT 20"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $code = (string) ($row['last_error_code'] ?? '');
                if ($row['status'] === 'failed' || $code !== '') {
                    $consecutive++;
                    continue;
                }
                break;
            }
        } catch (Throwable $exception) {
            $stats['error'] = $this->safeError($exception);
        }

        $threshold = max(1, (int) ($this->config['thresholds']['ai_recent_failures'] ?? 3));
        $disabled = false;
        foreach ($providers as $provider) {
            if (($provider['status'] ?? 'normal') !== 'normal') {
                $disabled = true;
            }
        }
        if ($disabled) {
            $status = 'maintenance';
        } elseif ($consecutive >= $threshold || $stats['failed'] >= $threshold) {
            $status = 'degraded';
        } else {
            $status = $providers === [] ? 'unknown' : 'ok';
        }

        return [
            'status' => $status,
            'providers' => $providers,
            'consecutive_failures' => $consecutive,
            'recent_failures' => $stats['failed'],
            'recent_rate_limited' => $stats['rate_limited'],
            'recent_timeouts' => $stats['timeout'],
            'recent_successes' => $stats['succeeded'],
            'configured_provider' => (string) ($this->config['provider'] ?? ''),
        ];
    }

    private function supplierStatus(): array
    {
        if ($this->supplierProbe !== null) {
            $probe = call_user_func($this->supplierProbe);
            $degraded = $probe === false || $probe === 'degraded' || $probe === 'timeout';
            return [
                'status' => $degraded ? 'degraded' : 'ok',
                'suppliers' => [],
                'driver' => 'injected',
                'recent_failures' => $degraded ? 1 : 0,
                'recent_timeouts' => $probe === 'timeout' ? 1 : 0,
                'last_sync_at' => null,
            ];
        }
        $since = $this->sinceDate((int) ($this->config['period_seconds'] ?? 300));
        $suppliers = [];
        try {
            $stmt = $this->pdo->query('SELECT `code`,`display_name`,`status`,`updated_at` FROM ' . $this->q('suppliers') . ' ORDER BY `id` ASC');
            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            return ['status' => 'unknown', 'suppliers' => [], 'error' => $this->safeError($exception)];
        }

        $recentFailures = 0;
        $recentTimeouts = 0;
        $lastSyncAt = null;
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . $this->q('publish_order_items')
                . ' WHERE `submit_error` IS NOT NULL AND `submit_error`<>:empty AND `updated_at`>=:since'
            );
            $stmt->execute(['empty' => '', 'since' => $since]);
            $recentFailures = (int) $stmt->fetchColumn();
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . $this->q('publish_order_items')
                . ' WHERE `submit_error` LIKE :timeout AND `updated_at`>=:since'
            );
            $stmt->execute(['timeout' => '%timeout%', 'since' => $since]);
            $recentTimeouts = (int) $stmt->fetchColumn();
            $lastSyncAt = $this->pdo->query('SELECT MAX(`last_synced_at`) FROM ' . $this->q('media_resources'))->fetchColumn();
        } catch (Throwable $exception) {
            // Some supplier tables may be absent in older test schemas; status falls back to provider catalog.
        }

        $threshold = max(1, (int) ($this->config['thresholds']['supplier_recent_failures'] ?? 3));
        $disabled = false;
        foreach ($suppliers as $supplier) {
            if (($supplier['status'] ?? 'normal') !== 'normal') {
                $disabled = true;
            }
        }
        if ($disabled) {
            $status = 'maintenance';
        } elseif ($recentFailures >= $threshold || $recentTimeouts >= (int) ($this->config['thresholds']['supplier_timeouts'] ?? 1)) {
            $status = 'degraded';
        } else {
            $status = $suppliers === [] ? 'unknown' : 'ok';
        }

        return [
            'status' => $status,
            'suppliers' => $suppliers,
            'driver' => (string) ($this->config['driver'] ?? ''),
            'recent_failures' => $recentFailures,
            'recent_timeouts' => $recentTimeouts,
            'last_sync_at' => $lastSyncAt !== false && $lastSyncAt !== null ? (string) $lastSyncAt : null,
        ];
    }

    private function queueStatus(): array
    {
        $stats = ['backlog' => 0, 'failed' => 0, 'max_attempts_reached' => 0, 'retrying' => 0, 'last_failed_at' => null];
        try {
            $now = $this->nowDate();
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . $this->q('geo_async_jobs')
                . " WHERE `status` IN ('queued','running') AND `available_at`<=:now"
            );
            $stmt->execute(['now' => $now]);
            $stats['backlog'] = (int) $stmt->fetchColumn();
            $stmt = $this->pdo->query('SELECT COUNT(*) FROM ' . $this->q('geo_async_jobs') . " WHERE `status`='failed'");
            $stats['failed'] = (int) $stmt->fetchColumn();
            $stmt = $this->pdo->query(
                'SELECT COUNT(*) FROM ' . $this->q('geo_async_jobs')
                . " WHERE `status`='failed' AND `attempts`>=`max_attempts`"
            );
            $stats['max_attempts_reached'] = (int) $stmt->fetchColumn();
            $stmt = $this->pdo->query(
                'SELECT COUNT(*) FROM ' . $this->q('geo_async_jobs')
                . " WHERE `status`='queued' AND `attempts`>0 AND `last_error_code` IS NOT NULL"
            );
            $stats['retrying'] = (int) $stmt->fetchColumn();
            $last = $this->pdo->query('SELECT MAX(`failed_at`) FROM ' . $this->q('geo_async_jobs'))->fetchColumn();
            $stats['last_failed_at'] = $last !== false && $last !== null ? (string) $last : null;
        } catch (Throwable $exception) {
            $stats['error'] = $this->safeError($exception);
        }
        return $stats;
    }

    private function orderStatus(): array
    {
        $staleHours = max(1, (int) ($this->config['thresholds']['order_stale_hours'] ?? 24));
        $cutoff = date('Y-m-d H:i:s', $this->now() - $staleHours * 3600);
        $result = ['stale_count' => 0, 'manual_review_count' => 0, 'invalid_status_count' => 0, 'samples' => []];
        try {
            $countStmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . $this->q('publish_orders')
                . " WHERE `status` IN ('queued','submitted','processing','manual_review') AND `updated_at`<:cutoff"
            );
            $countStmt->execute(['cutoff' => $cutoff]);
            $result['stale_count'] = (int) $countStmt->fetchColumn();
            $stmt = $this->pdo->prepare(
                'SELECT `id`,`order_no`,`status`,`updated_at` FROM ' . $this->q('publish_orders')
                . " WHERE `status` IN ('queued','submitted','processing','manual_review') AND `updated_at`<:cutoff"
                . ' ORDER BY `updated_at` ASC LIMIT 10'
            );
            $stmt->execute(['cutoff' => $cutoff]);
            $result['samples'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result['manual_review_count'] = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ' . $this->q('publish_orders') . " WHERE `status`='manual_review'"
            )->fetchColumn();
            $statusRows = $this->pdo->query(
                'SELECT `status`,COUNT(*) AS cnt FROM ' . $this->q('publish_orders') . ' GROUP BY `status`'
            )->fetchAll(PDO::FETCH_ASSOC);
            $invalidCount = 0;
            foreach ($statusRows as $statusRow) {
                if (!in_array((string) $statusRow['status'], self::ORDER_STATUSES, true)) {
                    $invalidCount += (int) $statusRow['cnt'];
                }
            }
            $result['invalid_status_count'] = $invalidCount;
        } catch (Throwable $exception) {
            $result['error'] = $this->safeError($exception);
        }
        return $result;
    }

    private function financialStatus(): array
    {
        $result = ['negative_wallets' => [], 'ledger_mismatches' => [], 'duplicate_refunds' => [], 'anomaly_count' => 0];
        try {
            $stmt = $this->pdo->query(
                'SELECT `company_id`,`available_balance`,`frozen_balance` FROM ' . $this->q('wallets')
                . ' WHERE `available_balance`<0 OR `frozen_balance`<0 ORDER BY `company_id` ASC'
            );
            $result['negative_wallets'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $rows = $this->pdo->query(
                'SELECT w.`company_id`,w.`available_balance`,w.`frozen_balance`,'
                . 'COALESCE(SUM(l.`available_delta`),0) AS ledger_available,'
                . 'COALESCE(SUM(l.`frozen_delta`),0) AS ledger_frozen'
                . ' FROM ' . $this->q('wallets') . ' w'
                . ' LEFT JOIN ' . $this->q('ledger_entries') . ' l ON l.`company_id`=w.`company_id`'
                . ' GROUP BY w.`company_id`,w.`available_balance`,w.`frozen_balance`'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $available = (string) $row['available_balance'];
                $frozen = (string) $row['frozen_balance'];
                $ledgerAvailable = (string) $row['ledger_available'];
                $ledgerFrozen = (string) $row['ledger_frozen'];
                if (bccomp($available, $ledgerAvailable, 2) !== 0 || bccomp($frozen, $ledgerFrozen, 2) !== 0) {
                    $result['ledger_mismatches'][] = [
                        'company_id' => (int) $row['company_id'],
                        'wallet_available' => $available,
                        'ledger_available' => $ledgerAvailable,
                        'wallet_frozen' => $frozen,
                        'ledger_frozen' => $ledgerFrozen,
                    ];
                }
            }

            $stmt = $this->pdo->query(
                'SELECT `company_id`,`order_id`,COUNT(*) AS duplicate_count FROM ' . $this->q('publish_refund_requests')
                . ' GROUP BY `company_id`,`order_id` HAVING COUNT(*)>1 ORDER BY `company_id` ASC,`order_id` ASC'
            );
            $result['duplicate_refunds'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            $result['error'] = $this->safeError($exception);
        }
        $result['anomaly_count'] = count($result['negative_wallets'])
            + count($result['ledger_mismatches'])
            + count($result['duplicate_refunds']);
        return $result;
    }

    private function diskStatus(): array
    {
        if (($this->config['probe']['disk'] ?? true) === false) {
            return ['status' => 'configured', 'free_bytes' => null];
        }
        try {
            if ($this->diskProbe !== null) {
                $bytes = (int) call_user_func($this->diskProbe);
            } else {
                $path = defined('ROOT_PATH') ? ROOT_PATH : getcwd();
                $bytes = (int) disk_free_space($path);
            }
            $threshold = max(1, (int) ($this->config['thresholds']['disk_free_min_bytes'] ?? 536870912));
            return [
                'status' => $bytes >= $threshold ? 'ok' : 'degraded',
                'free_bytes' => $bytes,
                'threshold_bytes' => $threshold,
            ];
        } catch (Throwable $exception) {
            return ['status' => 'degraded', 'free_bytes' => null, 'error' => $this->safeError($exception)];
        }
    }

    private function storageStatus(): array
    {
        if (($this->config['probe']['storage'] ?? false) === false && $this->storageProbe === null) {
            return ['status' => 'configured', 'probe' => 'disabled'];
        }
        try {
            if ($this->storageProbe !== null) {
                $result = call_user_func($this->storageProbe);
                if ($result === false) {
                    return ['status' => 'degraded', 'probe' => 'injected_failure'];
                }
                return ['status' => 'ok', 'probe' => 'injected_success'];
            }
            return ['status' => 'configured', 'probe' => 'disabled'];
        } catch (Throwable $exception) {
            return ['status' => 'degraded', 'probe' => 'exception', 'error' => $this->safeError($exception)];
        }
    }

    private function backupStatus(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT `operation`,`status`,`error_message` FROM ' . $this->q('geo_backup_records')
                . ' ORDER BY `id` DESC LIMIT 1'
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return ['status' => 'ok', 'last_operation' => null];
            }
            return [
                'status' => $row['status'] === 'failed' ? 'degraded' : 'ok',
                'last_operation' => $row['operation'],
                'last_status' => $row['status'],
                'last_error' => $row['error_message'],
            ];
        } catch (Throwable $exception) {
            return ['status' => 'ok', 'last_operation' => null, 'error' => $this->safeError($exception)];
        }
    }

    private function buildAlerts(array $health): array
    {
        $alerts = [];
        $db = $health['database'];
        if ($db['status'] === 'unavailable') {
            $alerts[] = $this->alert('dependency.database', 'critical', 'database', '数据库不可用', 'Database dependency is unavailable.');
        }
        $redis = $health['redis'];
        if ($redis['status'] === 'unavailable') {
            $alerts[] = $this->alert('dependency.redis', 'critical', 'redis', 'Redis不可用', 'Redis dependency is unavailable.');
        }
        $ai = $health['ai_provider'];
        if ($ai['status'] === 'degraded') {
            $alerts[] = $this->alert('ai.degraded', 'warning', 'ai', 'AI连续失败或异常', 'AI Provider recent failure threshold was reached.', [
                'consecutive_failures' => $ai['consecutive_failures'],
                'recent_failures' => $ai['recent_failures'],
            ]);
        }
        if (($ai['recent_rate_limited'] ?? 0) > 0) {
            $alerts[] = $this->alert('ai.rate_limited', 'warning', 'ai', 'AI限流', 'AI Provider rate limiting was observed.', [
                'recent_rate_limited' => $ai['recent_rate_limited'],
            ]);
        }
        $supplier = $health['supplier'];
        if ($supplier['status'] === 'degraded') {
            $alerts[] = $this->alert('supplier.degraded', 'warning', 'supplier', '供应商连续失败', 'Supplier recent failure threshold was reached.', [
                'recent_failures' => $supplier['recent_failures'],
            ]);
        }
        if (($supplier['recent_timeouts'] ?? 0) > 0) {
            $alerts[] = $this->alert('supplier.timeout', 'warning', 'supplier', '供应商超时', 'Supplier timeout events were observed.', [
                'recent_timeouts' => $supplier['recent_timeouts'],
            ]);
        }
        $queue = $health['queue'];
        if (($queue['failed'] ?? 0) > 0) {
            $alerts[] = $this->alert('queue.failed', 'warning', 'queue', '队列失败任务', 'Failed queue tasks were detected.', [
                'failed' => $queue['failed'],
                'max_attempts_reached' => $queue['max_attempts_reached'],
            ]);
        }
        if (($queue['backlog'] ?? 0) >= max(1, (int) ($this->config['thresholds']['queue_backlog'] ?? 20))) {
            $alerts[] = $this->alert('queue.backlog', 'warning', 'queue', '队列积压', 'Queue backlog threshold was reached.', [
                'backlog' => $queue['backlog'],
            ]);
        }
        if (($queue['max_attempts_reached'] ?? 0) > 0) {
            $alerts[] = $this->alert('queue.max_attempts', 'warning', 'queue', '重试次数耗尽', 'Queue tasks reached the retry limit.', [
                'max_attempts_reached' => $queue['max_attempts_reached'],
            ]);
        }
        $orders = $health['orders'];
        if (($orders['stale_count'] ?? 0) > 0) {
            $alerts[] = $this->alert('order.stale', 'warning', 'order', '订单停留异常', 'Orders exceeded the configured stale duration.', [
                'stale_count' => $orders['stale_count'],
                'sample_ids' => array_map(static fn(array $row): int => (int) $row['id'], $orders['samples']),
            ]);
        }
        $financial = $health['financial'];
        if (($financial['anomaly_count'] ?? 0) > 0) {
            $alerts[] = $this->alert('financial.anomaly', 'critical', 'financial', '财务一致性异常', 'Wallet, ledger, or refund anomalies were detected.', [
                'negative_wallets' => count($financial['negative_wallets']),
                'ledger_mismatches' => count($financial['ledger_mismatches']),
                'duplicate_refunds' => count($financial['duplicate_refunds']),
            ]);
        }
        if (($health['storage']['status'] ?? 'ok') === 'degraded') {
            $alerts[] = $this->alert('storage.unavailable', 'warning', 'storage', '存储异常', 'Storage/OSS probe reported an anomaly.');
        }
        if (($health['disk']['status'] ?? 'ok') === 'degraded') {
            $alerts[] = $this->alert('disk.low', 'warning', 'disk', '磁盘空间低', 'Free disk space is below the configured threshold.');
        }
        if (($health['backup']['status'] ?? 'ok') === 'degraded') {
            $alerts[] = $this->alert('backup.failed', 'critical', 'backup', '备份失败', 'The most recent backup record failed.');
        }
        return $alerts;
    }

    private function alert(string $ruleKey, string $severity, string $source, string $title, string $message, array $metadata = []): array
    {
        return [
            'rule_key' => $ruleKey,
            'severity' => $severity,
            'source' => $source,
            'title' => $title,
            'message' => substr($message, 0, 500),
            'metadata' => $metadata,
        ];
    }

    private function upsertAlerts(array $alerts): void
    {
        if ($alerts === []) {
            return;
        }
        $now = $this->nowDate();
        foreach ($alerts as $alert) {
            $ruleKey = $alert['rule_key'];
            $fingerprint = hash('sha256', $ruleKey . ':' . json_encode($alert['metadata'], JSON_UNESCAPED_SLASHES));
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . $this->q('geo_alert_events')
                . ' (`rule_key`,`severity`,`source`,`title`,`message`,`fingerprint`,`metadata_json`,'
                . '`status`,`event_count`,`first_seen_at`,`last_seen_at`,`created_at`,`updated_at`)'
                . ' VALUES (:rule,:severity,:source,:title,:message,:fingerprint,:metadata,'
                . '\'open\',1,:first_seen,:last_seen,:created_at,:updated_at)'
                . ' ON DUPLICATE KEY UPDATE `event_count`=`event_count`+1,`last_seen_at`=VALUES(`last_seen_at`),'
                . '`status`=\'open\',`resolved_at`=NULL,`updated_at`=VALUES(`updated_at`)'
            );
            $stmt->execute([
                'rule' => $ruleKey,
                'severity' => $alert['severity'],
                'source' => $alert['source'],
                'title' => $alert['title'],
                'message' => $alert['message'],
                'fingerprint' => $fingerprint,
                'metadata' => $alert['metadata'] === [] ? null : json_encode($alert['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'first_seen' => $now,
                'last_seen' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function resolveMissing(array $ruleKeys): void
    {
        if ($ruleKeys === []) {
            return;
        }
        $placeholders = [];
        $now = $this->nowDate();
        $params = [':resolved_at' => $now, ':updated_at' => $now];
        foreach (array_values($ruleKeys) as $index => $ruleKey) {
            $name = ':rule' . $index;
            $placeholders[] = $name;
            $params[$name] = $ruleKey;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->q('geo_alert_events')
            . " SET `status`='resolved',`resolved_at`=:resolved_at,`updated_at`=:updated_at"
            . " WHERE `status`='open' AND `rule_key` NOT IN (" . implode(',', $placeholders) . ')'
        );
        $stmt->execute($params);
    }

    private function writeSnapshot(array $health): int
    {
        $metrics = [
            'queue' => $health['queue'],
            'orders' => $health['orders'],
            'financial' => $health['financial'],
            'disk' => $health['disk'],
            'storage' => $health['storage'],
            'backup' => $health['backup'],
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . $this->q('geo_health_snapshots')
            . ' (`checked_at`,`overall_status`,`database_status`,`redis_status`,`ai_status`,`supplier_status`,'
            . '`queue_backlog`,`queue_failed`,`stale_orders`,`financial_anomalies`,`metrics_json`)'
            . ' VALUES (:checked,:overall,:db,:redis,:ai,:supplier,:backlog,:failed,:stale,:financial,:metrics)'
        );
        $stmt->execute([
            'checked' => $health['checked_at'],
            'overall' => $health['overall_status'],
            'db' => $health['database']['status'],
            'redis' => $health['redis']['status'],
            'ai' => $health['ai_provider']['status'],
            'supplier' => $health['supplier']['status'],
            'backlog' => $health['queue']['backlog'] ?? 0,
            'failed' => $health['queue']['failed'] ?? 0,
            'stale' => $health['orders']['stale_count'] ?? 0,
            'financial' => $health['financial']['anomaly_count'] ?? 0,
            'metrics' => json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function defaultConfig(): array
    {
        $config = (array) \think\Config::get('geo_monitor');
        return $config === [] ? require APP_PATH . 'extra' . DIRECTORY_SEPARATOR . 'geo_monitor.php' : $config;
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }

    private function nowDate(): string
    {
        return date('Y-m-d H:i:s', $this->now());
    }

    private function sinceDate(int $seconds): string
    {
        return date('Y-m-d H:i:s', $this->now() - max(1, $seconds));
    }

    private function latencyMs(float $started): int
    {
        return max(0, (int) round((microtime(true) - $started) * 1000));
    }

    private function safeCode(Throwable $exception): string
    {
        $code = $exception->getCode();
        return is_scalar($code) ? (string) $code : 'unknown';
    }

    private function safeError(Throwable $exception): array
    {
        return [
            'class' => str_replace('\\', '.', get_class($exception)),
            'code' => $this->safeCode($exception),
        ];
    }

    private function decodeJson($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function nullableString($value, int $max): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : substr($value, 0, $max);
    }

    private function q(string $name): string
    {
        $identifier = $this->prefix . $name;
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new RuntimeException('Monitoring table identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
}

<?php

declare(strict_types=1);

if (getenv('GEO_T17_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T17 operations test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T17_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T17_TEST_PREFIX') !== 't17_ops_'
) {
    fwrite(STDERR, "FAIL: T17 operations test safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\billing\BillingService;
use app\common\migration\MigrationManager;
use app\common\operations\MonitoringService;
use app\common\publishing\PublishCallbackService;
use app\common\publishing\PublishOrderService;
use app\common\publishing\PublishSyncService;
use app\common\supplier\MockSupplierAdapter;
use app\common\supplier\dto\OrderQuery;
use app\common\supplier\dto\OrderResult;
use app\common\supplier\dto\SupplierRawPayload;
use app\common\tenant\PdoAuditLogger;

final class T17OpsFailure extends RuntimeException
{
}

function t17Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T17OpsFailure($message);
    }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}

function t17HttpRequest(string $baseUrl, string $path): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'Accept: application/json',
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);
    $body = (string) @file_get_contents($baseUrl . $path, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
        $status = (int) $matches[1];
    }
    return ['status' => $status, 'body' => $body, 'payload' => json_decode($body, true)];
}

final class T17CallbackSupplierAdapter implements \app\common\supplier\contract\SupplierAdapter
{
    private MockSupplierAdapter $mock;

    public function __construct(string $environment)
    {
        $this->mock = new MockSupplierAdapter($environment, ['scenario' => 'default']);
    }

    public function supplierCode(): string
    {
        return $this->mock->supplierCode();
    }

    public function capabilities(): \app\common\supplier\dto\SupplierCapabilities
    {
        return $this->mock->capabilities();
    }

    public function listChannels(): array
    {
        return $this->mock->listChannels();
    }

    public function fetchMedia(\app\common\supplier\dto\MediaQuery $query): \app\common\supplier\dto\MediaPage
    {
        return $this->mock->fetchMedia($query);
    }

    public function queryMediaChanges(\app\common\supplier\dto\MediaChangeQuery $query): \app\common\supplier\dto\MediaChangePage
    {
        return $this->mock->queryMediaChanges($query);
    }

    public function submitOrder(\app\common\supplier\dto\OrderSubmitRequest $request): \app\common\supplier\dto\OrderSubmitResult
    {
        return $this->mock->submitOrder($request);
    }

    public function queryOrder(OrderQuery $query): OrderResult
    {
        $externalOrderNo = (string) $query->externalOrderNo();
        return new OrderResult(
            $externalOrderNo,
            \app\common\supplier\dto\OrderStatus::PUBLISHED,
            '2',
            true,
            'https://mock.invalid/published/' . $externalOrderNo,
            null,
            $query->idempotencyKey(),
            new SupplierRawPayload([
                'status' => '2',
                'items' => [
                    ['mediaId' => 'mock-media-official', 'popId' => 'pop-1', 'status' => '2'],
                ],
            ])
        );
    }

    public function parseCallback(\app\common\supplier\dto\CallbackEnvelope $envelope): \app\common\supplier\dto\ParsedCallback
    {
        return $this->mock->parseCallback($envelope);
    }
}

$host = (string) getenv('GEO_DATABASE_HOSTNAME');
$port = (int) getenv('GEO_DATABASE_HOSTPORT');
$database = (string) getenv('GEO_DATABASE_DATABASE');
$user = (string) getenv('GEO_DATABASE_USERNAME');
$password = (string) getenv('GEO_DATABASE_PASSWORD');
$prefix = 't17_ops_';
if ($host !== '127.0.0.1' || $port === 3306 || $database !== getenv('GEO_T17_TEST_DATABASE') || preg_match('/_test$/D', $database) !== 1) {
    fwrite(STDERR, "FAIL: T17 operations test requires an isolated non-3306 test database\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);
$manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
$failure = null;
$server = null;
$serverPipes = [];
$serverOut = tempnam(sys_get_temp_dir(), 'geo-t17-http-out-');
$serverErr = tempnam(sys_get_temp_dir(), 'geo-t17-http-err-');

try {
    try {
        $pdo->exec('DELETE FROM `' . $prefix . 'audit_logs`');
    } catch (Throwable $ignored) {
    }
    $applied = $manager->refresh();
    t17Assert(count($applied) === 22, 'T17 operations fixture applies all current migrations');

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    t17Assert($socket !== false, 'reserve local HTTP port');
    $socketName = stream_socket_get_name($socket, false);
    fclose($socket);
    $httpPort = (int) substr(strrchr((string) $socketName, ':'), 1);
    $baseUrl = 'http://127.0.0.1:' . $httpPort;
    $serverEnvironment = array_merge(getenv(), [
        'GEO_APP_ENV' => 'testing',
        'GEO_APP_TOKEN_KEY' => 'test-only-t17-http-token-key-20260808',
        'GEO_COMPANY_CONTACT_KEY' => 'base64:' . base64_encode(random_bytes(32)),
        'GEO_DATABASE_TYPE' => 'mysql',
        'GEO_DATABASE_HOSTNAME' => $host,
        'GEO_DATABASE_HOSTPORT' => (string) $port,
        'GEO_DATABASE_DATABASE' => $database,
        'GEO_DATABASE_USERNAME' => $user,
        'GEO_DATABASE_PASSWORD' => $password,
        'GEO_DATABASE_PREFIX' => $prefix,
        'GEO_AI_PROVIDER' => 'mock',
        'GEO_SUPPLIER_DRIVER' => 'mock',
        'GEO_CONTENT_STORAGE_DRIVER' => 'mock',
    ]);
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $httpPort, '-t', ROOT_PATH . 'public', ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $serverOut, 'a'], 2 => ['file', $serverErr, 'a']],
        $serverPipes,
        ROOT_PATH,
        $serverEnvironment,
        ['bypass_shell' => true]
    );
    t17Assert(is_resource($server), 'start local HTTP server');
    $deadline = microtime(true) + 10;
    do {
        if (t17HttpRequest($baseUrl, '/api/version')['status'] === 200) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    $health = t17HttpRequest($baseUrl, '/api/health');
    t17Assert(in_array((string) ($health['payload']['data']['status'] ?? ''), ['normal', 'abnormal'], true), 'enterprise health endpoint returns only a user-facing status');
    t17Assert(stripos($health['body'], 'dependencies') === false && stripos($health['body'], 'database') === false, 'enterprise health endpoint does not expose internal topology');

    $config = [
        'period_seconds' => 5,
        'thresholds' => [
            'queue_backlog' => 1,
            'queue_failed' => 1,
            'ai_recent_failures' => 1,
            'ai_rate_limited' => 1,
            'supplier_recent_failures' => 1,
            'supplier_timeouts' => 1,
            'order_stale_hours' => 1,
            'disk_free_min_bytes' => 1,
            'high_risk_window_seconds' => 60,
            'high_risk_max_actions' => 20,
        ],
        'probe' => ['database' => true, 'redis' => true, 'storage' => true, 'disk' => true],
    ];

    $healthy = (new MonitoringService($pdo, $prefix, $config, static fn() => null, static fn() => null, static fn() => true, static fn() => 1024 * 1024 * 1024))->health();
    t17Assert($healthy['overall_status'] === 'healthy', 'healthy probes report healthy overall status');

    $redisFailure = (new MonitoringService($pdo, $prefix, $config, static fn() => null, static function (): void {
        throw new RuntimeException('Redis down');
    }, static fn() => true, static fn() => 1024 * 1024 * 1024))->runCheck(true);
    t17Assert($redisFailure['health']['redis']['status'] === 'unavailable', 'Redis probe failure is detected');
    t17Assert(in_array('dependency.redis', array_column($redisFailure['alerts'], 'rule_key'), true), 'Redis failure creates alert');
    $redisRecovered = (new MonitoringService($pdo, $prefix, $config, static fn() => null, static fn() => null, static fn() => true, static fn() => 1024 * 1024 * 1024))->runCheck(true);
    t17Assert($redisRecovered['health']['redis']['status'] === 'ok', 'Redis recovery is detected after restart');

    $dbFailure = (new MonitoringService($pdo, $prefix, $config, static function (): void {
        throw new RuntimeException('DB down');
    }, static fn() => null, static fn() => true, static fn() => 1024 * 1024 * 1024))->runCheck(true);
    t17Assert(in_array('dependency.database', array_column($dbFailure['alerts'], 'rule_key'), true), 'Database transient failure creates alert');

    $aiFailure = (new MonitoringService($pdo, $prefix, $config, null, null, null, null, static fn() => 'degraded', static fn() => 'ok'))->runCheck(true);
    t17Assert(in_array('ai.degraded', array_column($aiFailure['alerts'], 'rule_key'), true), 'AI rate limit/consecutive failure creates alert');
    t17Assert(in_array('ai.rate_limited', array_column($aiFailure['alerts'], 'rule_key'), true), 'AI rate limiting creates alert');

    $supplierTimeout = (new MonitoringService($pdo, $prefix, $config, null, null, null, null, static fn() => 'ok', static fn() => 'timeout'))->runCheck(true);
    t17Assert(in_array('supplier.timeout', array_column($supplierTimeout['alerts'], 'rule_key'), true), 'Supplier timeout creates alert');

    $diskLow = (new MonitoringService($pdo, $prefix, $config, null, null, null, static fn() => 0))->runCheck(true);
    t17Assert(in_array('disk.low', array_column($diskLow['alerts'], 'rule_key'), true), 'Disk/storage anomaly creates alert');

    $storageFailure = (new MonitoringService($pdo, $prefix, $config, static fn() => null, static fn() => null, static function (): void {
        throw new RuntimeException('OSS down');
    }, static fn() => 1024 * 1024 * 1024))->runCheck(true);
    t17Assert(in_array('storage.unavailable', array_column($storageFailure['alerts'], 'rule_key'), true), 'Storage failure creates alert');

    $backupRecord = (new MonitoringService($pdo, $prefix, $config))->recordBackup('backup', 'failed', [
        'source_database' => 'geo_t17_test',
        'error_message' => 'injected backup failure',
    ]);
    t17Assert($backupRecord > 0, 'backup failure record is persisted');
    $backupHealth = (new MonitoringService($pdo, $prefix, $config))->health();
    t17Assert($backupHealth['backup']['status'] === 'degraded', 'backup failure is reflected in health');

    $alerts = (new MonitoringService($pdo, $prefix, $config))->alertEvents(1, 50);
    t17Assert($alerts['total'] >= 2, 'alert events are queryable');
    $firstAlertId = (int) $alerts['items'][0]['id'];
    $ack = (new MonitoringService($pdo, $prefix, $config))->acknowledgeAlert($firstAlertId, 42, 'T17 test ack', '127.0.0.1');
    t17Assert($ack['status'] === 'acknowledged', 'alert event can be acknowledged');

    $audit = new PdoAuditLogger($pdo, $prefix);
    $billing = new BillingService($pdo, $audit, $prefix);
    $billing->ensureWallet(90001);
    $credit = $billing->manualCredit(90001, '10.00', 1, 'T17 duplicate', 't17-duplicate-90001', '127.0.0.1');
    $creditAgain = $billing->manualCredit(90001, '10.00', 1, 'T17 duplicate', 't17-duplicate-90001', '127.0.0.1');
    t17Assert((int) $credit['id'] === (int) $creditAgain['id'], 'duplicate wallet operation returns the same ledger row');

    $now = time();
    $companyStmt = $pdo->prepare('INSERT INTO `' . $prefix . 'companies` (`name`,`status`,`createtime`,`updatetime`) VALUES (?,?,?,?)');
    $companyStmt->execute(['T17 callback company', 'normal', $now, $now]);
    $companyId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO `' . $prefix . 'wallets` (`company_id`,`currency`,`available_balance`,`frozen_balance`,`version`) VALUES (?,?,?,?,?)')
        ->execute([$companyId, 'CNY', '100.00', '0.00', 0]);
    $pdo->prepare('INSERT INTO `' . $prefix . 'keywords` (`company_id`,`keyword`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?)')
        ->execute([$companyId, 'T17 keyword', 'normal', 1, 1, $now, $now]);
    $keywordId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO `' . $prefix . 'questions` (`company_id`,`keyword_id`,`text`,`text_hash`,`source`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$companyId, $keywordId, 'T17 question', hash('sha256', 't17q', true), 'manual', 'normal', 1, 1, $now, $now]);
    $questionId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO `' . $prefix . 'prompt_templates` (`company_id`,`logical_key`,`version_no`,`name`,`scene`,`content`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$companyId, str_repeat('c', 32), 1, 'T17 prompt', 'article', 'Write', 'normal', 1, 1, $now, $now]);
    $promptId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO `' . $prefix . 'articles` (`company_id`,`keyword_id`,`question_id`,`title`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$companyId, $keywordId, $questionId, 'T17 article', 'draft', 1, 1, $now, $now]);
    $articleId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO `' . $prefix . 'article_versions` (`company_id`,`article_id`,`version_no`,`title_snapshot`,`content`,`source`,`prompt_template_id`,`created_by`,`createtime`) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$companyId, $articleId, 1, 'T17 article', 'body', 'manual', $promptId, 1, $now]);
    $versionId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE `' . $prefix . 'articles` SET `current_version_id`=? WHERE `id`=?')->execute([$versionId, $articleId]);
    $supplierId = (int) $pdo->query('SELECT `id` FROM `' . $prefix . 'suppliers` WHERE `code`=\'mock\' LIMIT 1')->fetchColumn();
    $channelId = (int) $pdo->query('SELECT `id` FROM `' . $prefix . 'channels` WHERE `code`=\'official_media\' LIMIT 1')->fetchColumn();
    $pdo->prepare('INSERT INTO `' . $prefix . 'media_resources` (`supplier_id`,`external_id`,`channel_id`,`name`,`cost_price`,`cost_currency`,`status`,`attributes_json`,`last_synced_at`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$supplierId, 'mock-media-official', $channelId, 'T17 media', '10.000000', 'CNY', 'active', '{}', date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    $mediaId = (int) $pdo->lastInsertId();

    $orderSvc = new PublishOrderService($pdo, $billing, $prefix);
    $preview = $orderSvc->preview($companyId, $articleId, $versionId, [$mediaId]);
    $order = $orderSvc->create($companyId, 1, $articleId, $versionId, [$mediaId], 't17-order-' . $companyId, $preview['price_fingerprint'], '127.0.0.1');
    $orderId = (int) $order['id'];
    $orderSvc->queueForSubmit($companyId, $orderId);
    $item = $pdo->query('SELECT `id` FROM `' . $prefix . 'publish_order_items` WHERE `order_id`=' . $orderId)->fetch();
    $orderSvc->transitionItem($companyId, $orderId, (int) $item['id'], 'queued', 'supplier', 'test queued', '127.0.0.1');
    $orderSvc->transitionItem($companyId, $orderId, (int) $item['id'], 'submitted', 'supplier', 'test submit', '127.0.0.1');
    $pdo->prepare('UPDATE `' . $prefix . 'publish_order_items` SET `supplier_submit_request_id`=?,`supplier_order_no`=? WHERE `id`=?')
        ->execute(['MM-MOCK-1001', 'MM-MOCK-1001', (int) $item['id']]);
    $orderSvc->transitionItem($companyId, $orderId, (int) $item['id'], 'processing', 'supplier', 'test processing', '127.0.0.1');

    $syncSvc = new PublishSyncService($pdo, $orderSvc, $prefix);
    $callbackSvc = new PublishCallbackService($pdo, $syncSvc, $orderSvc, $prefix);
    $compensation = $syncSvc->syncOrder(new T17CallbackSupplierAdapter('testing'), $companyId, $orderId, '127.0.0.1');
    t17Assert(
        $compensation['synced'] === 1,
        'callback loss compensation query recovers a submitted order result=' . json_encode($compensation, JSON_UNESCAPED_UNICODE)
    );

    $rawBody = json_encode([
        'supplier_code' => 'mock',
        'event_id' => 't17-callback-001',
        'company_id' => $companyId,
        'externalOrderNo' => 'MM-MOCK-1001',
        'status' => '2',
        'items' => [['mediaId' => 'mock-media-official', 'popId' => 'pop-1', 'status' => '2']],
    ], JSON_UNESCAPED_UNICODE);
    $pdo->prepare('INSERT INTO `' . $prefix . 'webhook_events` (`company_id`,`supplier_code`,`event_id`,`status`,`raw_payload_json`,`received_at`) VALUES (?,?,?,?,?,NOW())')
        ->execute([$companyId, 'mock', 't17-callback-001', 'received', $rawBody]);
    $webhookId = (int) $pdo->lastInsertId();
    $replay = $callbackSvc->replayEvent($webhookId, new T17CallbackSupplierAdapter('testing'));
    t17Assert($replay['status'] === 'processed', 'persisted callback can be replayed safely');
    $detail = $orderSvc->getDetail($companyId, $orderId, false);
    t17Assert($detail['items'][0]['status'] === 'published', 'callback replay uses the order state machine');
    $duplicateReplay = $callbackSvc->replayEvent($webhookId, new T17CallbackSupplierAdapter('testing'));
    t17Assert($duplicateReplay['status'] === 'duplicate', 'duplicate callback replay remains idempotent');
    $captureCount = (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'ledger_entries` WHERE `company_id`=' . $companyId . " AND `entry_type`='capture'")->fetchColumn();
    t17Assert($captureCount === 1, 'callback replay does not duplicate capture');
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    if ($server !== null && is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (is_string($serverOut) && is_file($serverOut)) {
        @unlink($serverOut);
    }
    if (is_string($serverErr) && is_file($serverErr)) {
        @unlink($serverErr);
    }
    try {
        try {
            $pdo->exec('DELETE FROM `' . $prefix . 'audit_logs`');
        } catch (Throwable $ignored) {
        }
        $manager->refresh();
    } catch (Throwable $cleanupException) {
        if ($failure === null) {
            $failure = $cleanupException;
        }
    }
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . get_class($failure) . ' file=' . basename($failure->getFile()) . ' line=' . $failure->getLine() . ' message=' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "RESULT: T17 operations, alert, recovery, and callback replay tests passed\n");

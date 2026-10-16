<?php

declare(strict_types=1);

if (getenv('GEO_T16_HTTP_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T16 HTTP access test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T16_HTTP_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T16_HTTP_TEST_PREFIX') !== 't16_http_'
) {
    fwrite(STDERR, "FAIL: T16 HTTP test safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;
use app\common\billing\BillingService;
use app\common\tenant\PdoAuditLogger;

final class T16HttpFailure extends RuntimeException
{
}

function t16HttpAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T16HttpFailure($message);
    }
}

function t16HttpRequest(string $baseUrl, string $method, string $path, array $body = [], array $headers = []): array
{
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", array_merge([
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ], $headers)),
            'content' => $body === [] ? '' : http_build_query($body),
            'ignore_errors' => true,
            'timeout' => 8,
            'follow_location' => 0,
        ],
    ]);
    $bodyText = @file_get_contents($baseUrl . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches)) {
        $status = (int) $matches[1];
    }
    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => is_string($bodyText) ? $bodyText : '',
        'payload' => json_decode(is_string($bodyText) ? $bodyText : '', true),
    ];
}

function t16WaitForServer(string $baseUrl): void
{
    $deadline = microtime(true) + 10;
    do {
        $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        if (@file_get_contents($baseUrl . '/api/version', false, $context) !== false) {
            return;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    throw new T16HttpFailure('local HTTP server did not become ready');
}

$config = [];
$section = 'global';
foreach (file(ROOT_PATH . '.env') ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    if (preg_match('/^\[(.+)\]$/', $line, $m) === 1) {
        $section = $m[1];
        continue;
    }
    $parts = explode('=', $line, 2);
    if (count($parts) === 2) {
        $config[$section][trim($parts[0])] = trim($parts[1], " \t\"\r\n");
    }
}
$db = $config['database'] ?? [];
$host = $db['hostname'] ?? '';
$port = (int) ($db['hostport'] ?? 0);
$database = $db['database'] ?? '';
$username = $db['username'] ?? '';
$password = $db['password'] ?? '';
if ($host === '' || $database === '' || $username === '') {
    fwrite(STDERR, "FAIL: T16 HTTP database configuration is incomplete\n");
    exit(1);
}

$prefix = 't16_http_';
$pdo = null;
$process = null;
$pipes = [];
$logOut = tempnam(sys_get_temp_dir(), 'geo-t16-http-out-');
$logErr = tempnam(sys_get_temp_dir(), 'geo-t16-http-err-');

try {
    $pdo = new \PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $username,
        $password,
        [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
    $applied = $manager->migrateThrough('202608080018_seed_internal_permissions');
    t16HttpAssert(count($applied) === 18, 'T16 HTTP fixture applies all current migrations; count=' . count($applied));

    $now = time();
    $companyStmt = $pdo->prepare('INSERT INTO `' . $prefix . 'companies` (`name`,`status`,`createtime`,`updatetime`) VALUES (:name,:status,:ct,:ut)');
    $companyStmt->execute(['name' => 'T16企业', 'status' => 'normal', 'ct' => $now, 'ut' => $now]);
    $companyId = (int) $pdo->lastInsertId();
    $userStmt = $pdo->prepare('INSERT INTO `' . $prefix . 'user` (`company_id`,`username`,`password_hash`,`role`,`status`,`createtime`,`updatetime`) VALUES (:cid,:user,:hash,:role,:status,:ct,:ut)');
    $userStmt->execute([
        'cid' => $companyId,
        'user' => 't16-http-owner',
        'hash' => password_hash('T16-Http-Password-2026', PASSWORD_DEFAULT),
        'role' => 'company_owner',
        'status' => 'normal',
        'ct' => $now,
        'ut' => $now,
    ]);

    $billing = new BillingService($pdo, new PdoAuditLogger($pdo, $prefix), $prefix);
    $billing->ensureWallet($companyId);
    $firstCredit = $billing->manualCredit($companyId, '5.00', 42, 'T16 idempotency setup', 't16-idem-' . $companyId, '127.0.0.1');
    $secondCredit = $billing->manualCredit($companyId, '5.00', 42, 'T16 idempotency setup', 't16-idem-' . $companyId, '127.0.0.1');
    t16HttpAssert((int) $firstCredit['id'] === (int) $secondCredit['id'], 'Repeated wallet adjustment returns the same ledger entry.');
    $walletAfter = $billing->getWallet($companyId);
    t16HttpAssert(($walletAfter['available_balance'] ?? '0.00') === '5.00', 'Repeated wallet adjustment does not duplicate balance.');
    $ledgerCount = (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'ledger_entries` WHERE `company_id`=' . (int) $companyId)->fetchColumn();
    t16HttpAssert($ledgerCount === 1, 'Repeated wallet adjustment creates exactly one ledger row.');

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    t16HttpAssert($socket !== false, 'reserve local HTTP port');
    $socketName = stream_socket_get_name($socket, false);
    fclose($socket);
    $httpPort = (int) substr(strrchr((string) $socketName, ':'), 1);
    $baseUrl = 'http://127.0.0.1:' . $httpPort;
    $serverEnvironment = array_merge(getenv(), [
        'GEO_APP_ENV' => 'testing',
        'GEO_APP_TOKEN_KEY' => 'test-only-t16-http-token-key-20260808',
        'GEO_COMPANY_CONTACT_KEY' => 'base64:' . base64_encode(random_bytes(32)),
        'GEO_DATABASE_TYPE' => 'mysql',
        'GEO_DATABASE_HOSTNAME' => $host,
        'GEO_DATABASE_HOSTPORT' => (string) $port,
        'GEO_DATABASE_DATABASE' => $database,
        'GEO_DATABASE_USERNAME' => $username,
        'GEO_DATABASE_PASSWORD' => $password,
        'GEO_DATABASE_PREFIX' => $prefix,
        'GEO_CONTENT_STORAGE_DRIVER' => 'mock',
        'GEO_AI_PROVIDER' => 'mock',
        'GEO_SUPPLIER_DRIVER' => 'mock',
        'GEO_BILLING_MANUAL_ADJUST_ENABLED' => 'true',
    ]);
    $process = proc_open(
        [
            PHP_BINARY,
            '-S',
            '127.0.0.1:' . $httpPort,
            '-t',
            ROOT_PATH . 'public',
            ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'router.php',
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $logOut, 'a'],
            2 => ['file', $logErr, 'a'],
        ],
        $pipes,
        ROOT_PATH,
        $serverEnvironment,
        ['bypass_shell' => true]
    );
    t16HttpAssert(is_resource($process), 'start local HTTP server');
    t16WaitForServer($baseUrl);

    $login = t16HttpRequest($baseUrl, 'POST', '/api/auth/login', [
        'account' => 't16-http-owner',
        'password' => 'T16-Http-Password-2026',
    ]);
    t16HttpAssert($login['status'] === 200, 'enterprise login succeeds');
    $token = (string) ($login['payload']['data']['token'] ?? '');
    t16HttpAssert($token !== '', 'enterprise login returns a token');
    $headers = ['Authorization: Bearer ' . $token];

    $internal = t16HttpRequest($baseUrl, 'GET', '/internal/company/index', [], $headers);
    t16HttpAssert(
        $internal['status'] !== 200 || stripos($internal['body'], 'T16企业') === false,
        'enterprise Bearer token cannot open internal company data'
    );

    $anonymous = t16HttpRequest($baseUrl, 'GET', '/internal/company/index');
    t16HttpAssert(
        $anonymous['status'] === 302 || stripos($anonymous['body'], 'login') !== false,
        'unauthenticated internal access is rejected; status=' . $anonymous['status'] . ' body=' . substr($anonymous['body'], 0, 240)
    );

    echo "PASS: T16 HTTP access boundary tests passed\n";
} finally {
    if ($process !== null && is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($pdo !== null) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $like = addcslashes($prefix, '_%');
        $tables = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '" . $like . "%'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    if (is_string($logOut) && is_file($logOut)) {
        @unlink($logOut);
    }
    if (is_string($logErr) && is_file($logErr)) {
        @unlink($logErr);
    }
}

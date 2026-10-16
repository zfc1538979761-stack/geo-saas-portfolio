<?php

declare(strict_types=1);

if (getenv('GEO_T04_HTTP_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T04 HTTP company profile test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T04_HTTP_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T04_HTTP_TEST_PREFIX') !== 't04_http_'
) {
    fwrite(STDERR, "FAIL: T04 HTTP test safety gate rejected the environment\n");
    exit(1);
}
define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;

final class T04HttpFailure extends RuntimeException
{
}

function t04HttpAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T04HttpFailure($message);
    }
}

function t04HttpRequest(
    string $baseUrl,
    string $method,
    string $path,
    array $body = [],
    array $headers = []
): array {
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", array_merge([
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ], $headers)),
            'content' => $body === [] ? '' : http_build_query($body),
            'ignore_errors' => true,
            'timeout' => 5,
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

function t04WaitForServer(string $baseUrl): void
{
    $deadline = microtime(true) + 10;
    do {
        $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        if (@file_get_contents($baseUrl . '/api/version', false, $context) !== false) {
            return;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    throw new T04HttpFailure('local HTTP server did not become ready');
}

$environment = parse_ini_file(ROOT_PATH . '.env', true, INI_SCANNER_RAW);
$databaseConfig = is_array($environment) ? ($environment['database'] ?? []) : [];
$appConfig = is_array($environment) ? ($environment['app'] ?? []) : [];
$processValue = static function (string $key, $fallback) {
    $value = getenv($key);
    return $value === false ? $fallback : $value;
};
$database = (string) $processValue('GEO_DATABASE_DATABASE', $databaseConfig['database'] ?? '');
$host = (string) $processValue('GEO_DATABASE_HOSTNAME', $databaseConfig['hostname'] ?? '');
$port = (int) $processValue('GEO_DATABASE_HOSTPORT', $databaseConfig['hostport'] ?? 0);
$username = (string) $processValue('GEO_DATABASE_USERNAME', $databaseConfig['username'] ?? '');
$password = (string) $processValue('GEO_DATABASE_PASSWORD', $databaseConfig['password'] ?? '');
$tokenKey = (string) $processValue('GEO_APP_TOKEN_KEY', $appConfig['token_key'] ?? '');
if ($database === '' || $host === '' || $port < 1 || $username === '' || $tokenKey === '') {
    fwrite(STDERR, "FAIL: T04 HTTP database configuration is incomplete\n");
    exit(1);
}

$prefix = 't04_http_';
$tables = [
    $prefix . 'questions',
    $prefix . 'keywords',
    $prefix . 'company_profiles',
    $prefix . 'audit_logs',
    $prefix . 'geo_auth_throttles',
    $prefix . 'user_token',
    $prefix . 'user',
    $prefix . 'companies',
    $prefix . 'geo_async_jobs',
    $prefix . 'geo_migrations',
];
$pdo = null;
$process = null;
$pipes = [];
$before = [];
$failure = null;
$logOut = tempnam(sys_get_temp_dir(), 'geo-t04-http-out-');
$logErr = tempnam(sys_get_temp_dir(), 'geo-t04-http-err-');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $before = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . ' WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        t04HttpAssert(!in_array($table, $before, true), 'T04 HTTP namespace must be empty');
    }
    $manager = new MigrationManager(
        $pdo,
        ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations',
        $prefix
    );
    t04HttpAssert(
        count($manager->migrateThrough('202608010005_create_keywords_and_questions')) === 5,
        'T01-T05 HTTP migrations apply'
    );

    $now = time();
    $insertCompany = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'companies` (`name`,`status`,`createtime`,`updatetime`)'
        . ' VALUES (:name,:status,:created,:updated)'
    );
    $insertUser = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'user`'
        . ' (`company_id`,`username`,`password_hash`,`role`,`status`,`createtime`,`updatetime`)'
        . ' VALUES (:company_id,:username,:password_hash,:role,:status,:created,:updated)'
    );
    $fixtures = [];
    foreach (['a', 'b'] as $suffix) {
        $insertCompany->execute([
            'name' => 'HTTP企业' . strtoupper($suffix),
            'status' => 'normal',
            'created' => $now,
            'updated' => $now,
        ]);
        $companyId = (int) $pdo->lastInsertId();
        $insertUser->execute([
            'company_id' => $companyId,
            'username' => 't04-http-owner-' . $suffix,
            'password_hash' => password_hash('T04-Http-Password-2026', PASSWORD_DEFAULT),
            'role' => 'company_owner',
            'status' => 'normal',
            'created' => $now,
            'updated' => $now,
        ]);
        $fixtures[$suffix] = ['company_id' => $companyId, 'user_id' => (int) $pdo->lastInsertId()];
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    t04HttpAssert($socket !== false, 'reserve local HTTP port');
    $socketName = stream_socket_get_name($socket, false);
    fclose($socket);
    $httpPort = (int) substr(strrchr((string) $socketName, ':'), 1);
    $baseUrl = 'http://127.0.0.1:' . $httpPort;
    $contactKey = 'base64:' . base64_encode(random_bytes(32));
    $serverEnvironment = array_merge(getenv(), [
        'GEO_APP_ENV' => 'testing',
        'GEO_APP_TOKEN_KEY' => 'test-only-t04-http-token-key-20260730',
        'GEO_COMPANY_CONTACT_KEY' => $contactKey,
        'GEO_DATABASE_TYPE' => 'mysql',
        'GEO_DATABASE_HOSTNAME' => $host,
        'GEO_DATABASE_HOSTPORT' => (string) $port,
        'GEO_DATABASE_DATABASE' => $database,
        'GEO_DATABASE_USERNAME' => $username,
        'GEO_DATABASE_PASSWORD' => $password,
        'GEO_DATABASE_PREFIX' => $prefix,
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
    t04HttpAssert(is_resource($process), 'start local HTTP server');
    t04WaitForServer($baseUrl);

    $tokens = [];
    foreach (['a', 'b'] as $suffix) {
        $login = t04HttpRequest($baseUrl, 'POST', '/api/auth/login', [
            'account' => 't04-http-owner-' . $suffix,
            'password' => 'T04-Http-Password-2026',
        ]);
        t04HttpAssert($login['status'] === 200, 'enterprise login ' . $suffix);
        $tokens[$suffix] = (string) ($login['payload']['data']['token'] ?? '');
        t04HttpAssert($tokens[$suffix] !== '', 'login returns token ' . $suffix);
    }

    $unauthenticated = t04HttpRequest($baseUrl, 'GET', '/api/company/profile');
    t04HttpAssert($unauthenticated['status'] === 401, 'profile requires authentication');
    $cookieOnly = t04HttpRequest(
        $baseUrl,
        'GET',
        '/api/company/profile',
        [],
        ['Cookie: token=' . $tokens['a']]
    );
    t04HttpAssert($cookieOnly['status'] === 401, 'cookie-only profile access is rejected for CSRF safety');

    $initialA = t04HttpRequest(
        $baseUrl,
        'GET',
        '/api/company/profile?company_id=' . $fixtures['b']['company_id'],
        [],
        [
            'Authorization: Bearer ' . $tokens['a'],
            'Company-Id: ' . $fixtures['b']['company_id'],
            'X-Company-Id: ' . $fixtures['b']['company_id'],
        ]
    );
    t04HttpAssert($initialA['status'] === 200, 'A reads its profile');
    t04HttpAssert(
        ($initialA['payload']['data']['company_name'] ?? '') === 'HTTP企业A',
        'query and headers cannot switch A to B'
    );
    t04HttpAssert(
        !array_key_exists('company_id', (array) ($initialA['payload']['data'] ?? [])),
        'public profile response does not expose internal company_id'
    );

    $spoofedBody = t04HttpRequest(
        $baseUrl,
        'POST',
        '/api/company/profile',
        ['company_name' => '越权', 'company_id' => $fixtures['b']['company_id']],
        ['Authorization: Bearer ' . $tokens['a']]
    );
    t04HttpAssert($spoofedBody['status'] === 422, 'body company_id is rejected');

    $savedA = t04HttpRequest(
        $baseUrl,
        'POST',
        '/api/company/profile',
        [
            'company_name' => 'HTTP企业A更新',
            'brand' => '<img src=x onerror=alert(1)>',
            'industry' => '制造业',
            'products_services' => json_encode(['注塑件', 'GEO服务'], JSON_UNESCAPED_UNICODE),
            'website' => 'https://a.example.test',
            'introduction' => '<script>globalThis.profileXss=true</script>',
            'mobile' => '+8613800138000',
            'email' => 'OWNER-A@EXAMPLE.TEST',
            'status' => 'disabled',
            'verify_status' => 'verified',
        ],
        ['Authorization: Bearer ' . $tokens['a']]
    );
    t04HttpAssert($savedA['status'] === 200, 'A saves its profile');
    t04HttpAssert(
        ($savedA['payload']['data']['contacts']['email'] ?? '') === 'owner-a@example.test',
        'authorized A reads normalized contacts'
    );
    t04HttpAssert(
        (int) ($savedA['payload']['data']['profile_completeness'] ?? 0) === 100,
        'complete profile returns 100 completeness'
    );
    t04HttpAssert(($savedA['payload']['data']['ai_ready'] ?? false) === true, 'complete profile is AI-ready');

    foreach (['javascript:alert(1)', 'data:text/html,test', 'ftp://example.test'] as $unsafeUrl) {
        $invalid = t04HttpRequest(
            $baseUrl,
            'POST',
            '/api/company/profile',
            ['company_name' => 'HTTP企业A更新', 'website' => $unsafeUrl],
            ['Authorization: Bearer ' . $tokens['a']]
        );
        t04HttpAssert($invalid['status'] === 422, 'unsafe URL is rejected');
    }
    foreach ([
        ['company_name' => ''],
        ['company_name' => '企业', 'mobile' => '123'],
        ['company_name' => '企业', 'email' => 'bad@'],
        ['company_name' => '企业', 'products_services' => '{"bad":true}'],
        ['company_name' => '企业', 'products_services' => '["重复","重复"]'],
    ] as $invalidBody) {
        $invalid = t04HttpRequest(
            $baseUrl,
            'POST',
            '/api/company/profile',
            $invalidBody,
            ['Authorization: Bearer ' . $tokens['a']]
        );
        t04HttpAssert($invalid['status'] === 422, 'invalid field input is rejected');
    }

    $readB = t04HttpRequest(
        $baseUrl,
        'GET',
        '/api/company/profile?company_id=' . $fixtures['a']['company_id'],
        [],
        [
            'Authorization: Bearer ' . $tokens['b'],
            'X-Company-Id: ' . $fixtures['a']['company_id'],
        ]
    );
    t04HttpAssert($readB['status'] === 200, 'B reads profile without foreign existence signal');
    t04HttpAssert(
        ($readB['payload']['data']['company_name'] ?? '') === 'HTTP企业B',
        'B cannot read A profile'
    );
    $writeBAsA = t04HttpRequest(
        $baseUrl,
        'POST',
        '/api/company/profile',
        ['company_name' => '伪造修改A', 'company_id' => $fixtures['a']['company_id']],
        ['Authorization: Bearer ' . $tokens['b']]
    );
    t04HttpAssert($writeBAsA['status'] === $spoofedBody['status'], 'cross-tenant body failures are indistinguishable');
    $confirmA = t04HttpRequest(
        $baseUrl,
        'GET',
        '/api/company/profile',
        [],
        ['Authorization: Bearer ' . $tokens['a']]
    );
    t04HttpAssert(
        ($confirmA['payload']['data']['company_name'] ?? '') === 'HTTP企业A更新',
        'B cannot modify A'
    );

    $page = t04HttpRequest($baseUrl, 'GET', '/index/company/profile');
    t04HttpAssert($page['status'] === 200, 'company profile page is available');
    t04HttpAssert(strpos($page['body'], '企业资料') !== false, 'page contains profile editor');
    t04HttpAssert(stripos($page['body'], 'innerHTML') === false, 'page does not use unsafe innerHTML');
    t04HttpAssert(
        count(array_filter($page['headers'], static function (string $header): bool {
            return stripos($header, 'Content-Security-Policy:') === 0;
        })) === 1,
        'page has Content-Security-Policy'
    );

    $rawCiphertext = (string) $pdo->query(
        'SELECT `contacts_ciphertext` FROM `' . $prefix . 'company_profiles`'
        . ' WHERE `company_id`=' . (int) $fixtures['a']['company_id']
    )->fetchColumn();
    t04HttpAssert(strpos($rawCiphertext, '13800138000') === false, 'database contact mobile is encrypted');
    t04HttpAssert(stripos($rawCiphertext, 'owner-a@example.test') === false, 'database contact email is encrypted');
    $audits = $pdo->query(
        'SELECT `detail_json` FROM `' . $prefix . 'audit_logs`'
        . " WHERE `action`='company_profile_saved'"
    )->fetchAll(PDO::FETCH_COLUMN);
    t04HttpAssert(count($audits) === 1, 'successful API save creates one profile audit');
    foreach ($audits as $audit) {
        t04HttpAssert(strpos((string) $audit, '13800138000') === false, 'audit omits mobile');
        t04HttpAssert(stripos((string) $audit, 'owner-a@example.test') === false, 'audit omits email');
    }

    $pdo->prepare('UPDATE `' . $prefix . 'user` SET `status`=:status WHERE `id`=:id')
        ->execute(['status' => 'disabled', 'id' => $fixtures['a']['user_id']]);
    $disabledUser = t04HttpRequest(
        $baseUrl,
        'GET',
        '/api/company/profile',
        [],
        ['Authorization: Bearer ' . $tokens['a']]
    );
    t04HttpAssert($disabledUser['status'] === 401, 'disabled user cannot access profile');
    $pdo->prepare('UPDATE `' . $prefix . 'companies` SET `status`=:status WHERE `id`=:id')
        ->execute(['status' => 'disabled', 'id' => $fixtures['b']['company_id']]);
    $disabledCompany = t04HttpRequest(
        $baseUrl,
        'GET',
        '/api/company/profile',
        [],
        ['Authorization: Bearer ' . $tokens['b']]
    );
    t04HttpAssert($disabledCompany['status'] === 401, 'disabled company cannot access profile');

    proc_terminate($process);
    proc_close($process);
    $process = null;
    $serverLogs = (string) @file_get_contents($logOut) . (string) @file_get_contents($logErr);
    t04HttpAssert(strpos($serverLogs, '13800138000') === false, 'server logs omit mobile');
    t04HttpAssert(stripos($serverLogs, 'owner-a@example.test') === false, 'server logs omit email');

    fwrite(
        STDOUT,
        "PASS: T04 HTTP auth, CRUD, validation, A/B isolation, CSRF, page CSP/XSS, encryption, audit, and disabled states\n"
    );
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if ($pdo instanceof PDO) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $after = $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($before !== [] && $after !== $before) {
            $failure = new T04HttpFailure('T04 HTTP cleanup changed database table list');
        }
    }
    if (is_string($logOut)) {
        @unlink($logOut);
    }
    if (is_string($logErr)) {
        @unlink($logErr);
    }
}

if ($failure !== null) {
    fwrite(
        STDERR,
        'FAIL: ' . get_class($failure)
        . ' file=' . basename($failure->getFile())
        . ' line=' . (string) $failure->getLine()
        . ' message=' . preg_replace(
            '/(?:mobile|email|contact|password|token|secret|authorization|cookie|key)\s*[=:]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $failure->getMessage()
        )
        . PHP_EOL
    );
    exit(1);
}

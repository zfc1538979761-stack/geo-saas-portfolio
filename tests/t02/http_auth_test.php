<?php

declare(strict_types=1);

if (getenv('GEO_T02_HTTP_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T02 HTTP authentication test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T02_HTTP_TEST_ALLOW_SHARED_DATABASE') !== '1'
) {
    fwrite(STDERR, "FAIL: T02 HTTP test safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;

final class T02HttpTestFailure extends RuntimeException
{
}

function t02HttpFail(string $message): void
{
    throw new T02HttpTestFailure($message);
}

function t02HttpAssert(bool $condition, string $message): void
{
    if (!$condition) {
        t02HttpFail($message);
    }
}

function t02HttpRequest(
    string $baseUrl,
    string $method,
    string $path,
    array $body = [],
    array $headers = []
): array {
    $headerLines = array_merge(
        ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        $headers
    );
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body === [] ? '' : http_build_query($body),
            'ignore_errors' => true,
            'timeout' => 5,
            'follow_location' => 0,
        ],
    ]);
    try {
        $responseBody = file_get_contents($baseUrl . $path, false, $context);
    } catch (Throwable $exception) {
        t02HttpFail('HTTP request failed path=' . $path);
    }
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches)) {
        $status = (int) $matches[1];
    }
    $payload = json_decode((string) $responseBody, true);

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'payload' => is_array($payload) ? $payload : null,
        'body' => (string) $responseBody,
    ];
}

function t02WaitForServer(string $baseUrl): void
{
    $deadline = microtime(true) + 10;
    do {
        $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        if (@file_get_contents($baseUrl . '/api/version', false, $context) !== false) {
            return;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);

    t02HttpFail('local HTTP server did not become ready');
}

$prefix = (string) getenv('GEO_T02_HTTP_TEST_PREFIX');
if ($prefix !== 't02_http_') {
    fwrite(STDERR, "FAIL: T02 HTTP test prefix safety gate rejected the prefix\n");
    exit(1);
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
    fwrite(STDERR, "FAIL: T02 HTTP test database configuration is incomplete\n");
    exit(1);
}

$pdo = null;
$process = null;
$pipes = [];
$before = [];
$failure = null;
$tables = [
    't02_http_audit_logs',
    't02_http_companies',
    't02_http_company_profiles',
    't02_http_geo_async_jobs',
    't02_http_geo_auth_throttles',
    't02_http_geo_migrations',
    't02_http_keywords',
    't02_http_questions',
    't02_http_user',
    't02_http_user_token',
];
$dropOrder = [
    't02_http_questions',
    't02_http_keywords',
    't02_http_company_profiles',
    't02_http_audit_logs',
    't02_http_geo_auth_throttles',
    't02_http_user_token',
    't02_http_user',
    't02_http_companies',
    't02_http_geo_async_jobs',
    't02_http_geo_migrations',
];

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
    $staleTables = array_values(array_filter($before, static function ($table) use ($prefix): bool {
        return strpos((string) $table, $prefix) === 0;
    }));
    if ($staleTables !== []) {
        if (
            getenv('GEO_T02_HTTP_TEST_CLEAN_STALE') !== '1'
            || array_diff($staleTables, $tables) !== []
        ) {
            t02HttpFail('T02 HTTP namespace is not empty');
        }
        foreach ($dropOrder as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $before = $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    $manager = new MigrationManager(
        $pdo,
        ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations',
        $prefix
    );
    $manager->migrateThrough('202608010005_create_keywords_and_questions');
    $now = time();
    $insertCompany = $pdo->prepare(
        'INSERT INTO `t02_http_companies` (`name`,`status`,`createtime`,`updatetime`)'
        . ' VALUES (:name,:status,:created,:updated)'
    );
    $insertCompany->execute([
        'name' => 'T02测试企业',
        'status' => 'normal',
        'created' => $now,
        'updated' => $now,
    ]);
    $companyId = (int) $pdo->lastInsertId();
    $insertUser = $pdo->prepare(
        'INSERT INTO `t02_http_user`'
        . ' (`company_id`,`username`,`password_hash`,`role`,`status`,`createtime`,`updatetime`)'
        . ' VALUES (:company_id,:username,:password_hash,:role,:status,:created,:updated)'
    );
    $insertUser->execute([
        'company_id' => $companyId,
        'username' => 't02-owner',
        'password_hash' => password_hash('Initial-Password-2026', PASSWORD_DEFAULT),
        'role' => 'company_owner',
        'status' => 'normal',
        'created' => $now,
        'updated' => $now,
    ]);
    $userId = (int) $pdo->lastInsertId();

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if ($socket === false) {
        t02HttpFail('unable to reserve a local test port');
    }
    $socketName = stream_socket_get_name($socket, false);
    fclose($socket);
    $httpPort = (int) substr(strrchr((string) $socketName, ':'), 1);
    $baseUrl = 'http://127.0.0.1:' . $httpPort;

    $serverEnvironment = array_merge(getenv(), [
        'GEO_APP_ENV' => 'testing',
        'GEO_APP_TOKEN_KEY' => 'test-only-t02-http-token-key-20260730',
        'GEO_DATABASE_TYPE' => 'mysql',
        'GEO_DATABASE_HOSTNAME' => $host,
        'GEO_DATABASE_HOSTPORT' => (string) $port,
        'GEO_DATABASE_DATABASE' => $database,
        'GEO_DATABASE_USERNAME' => $username,
        'GEO_DATABASE_PASSWORD' => $password,
        'GEO_DATABASE_PREFIX' => $prefix,
    ]);
    $command = [
        PHP_BINARY,
        '-S',
        '127.0.0.1:' . $httpPort,
        '-t',
        ROOT_PATH . 'public',
        ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'router.php',
    ];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['file', 'NUL', 'a'],
            2 => ['file', 'NUL', 'a'],
        ],
        $pipes,
        ROOT_PATH,
        $serverEnvironment,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        t02HttpFail('unable to start local HTTP server');
    }
    t02WaitForServer($baseUrl);

    $wrongAccount = t02HttpRequest($baseUrl, 'POST', '/api/auth/login', [
        'account' => 'missing-owner',
        'password' => 'Initial-Password-2026',
    ]);
    $wrongCredentialResponse = t02HttpRequest($baseUrl, 'POST', '/api/auth/login', [
        'account' => 't02-owner',
        'password' => 'wrong-password',
    ]);
    t02HttpAssert($wrongAccount['status'] === 401, 'wrong account returns HTTP 401');
    t02HttpAssert($wrongCredentialResponse['status'] === 401, 'wrong password returns HTTP 401');
    t02HttpAssert(
        $wrongAccount['payload']['msg'] === $wrongCredentialResponse['payload']['msg'],
        'wrong account and password messages are identical'
    );

    $login = t02HttpRequest(
        $baseUrl,
        'POST',
        '/api/auth/login',
        [
            'account' => 't02-owner',
            'password' => 'Initial-Password-2026',
            'company_id' => 999999,
            'redirect' => 'https://attacker.invalid/',
            'token' => 'attacker-fixed-token',
        ],
        ['Cookie: token=test-only-fixed-cookie']
    );
    t02HttpAssert($login['status'] === 200, 'correct credentials login');
    t02HttpAssert(is_array($login['payload']), 'login uses JSON envelope');
    $token = (string) ($login['payload']['data']['token'] ?? '');
    t02HttpAssert($token !== '' && $token !== 'attacker-fixed-token', 'login prevents session fixation');
    t02HttpAssert(
        (int) $login['payload']['data']['user']['company']['id'] === $companyId,
        'login ignores client company_id'
    );
    foreach ($login['headers'] as $header) {
        t02HttpAssert(stripos($header, 'Location:') !== 0, 'login does not perform an open redirect');
    }
    t02HttpAssert(
        count(array_filter($login['headers'], static function (string $header): bool {
            return stripos($header, 'Cache-Control:') === 0
                && stripos($header, 'no-store') !== false;
        })) === 1,
        'login response disables sensitive response caching'
    );

    $current = t02HttpRequest(
        $baseUrl,
        'GET',
        '/api/auth/current?company_id=999999',
        [],
        [
            'Authorization: Bearer ' . $token,
            'Company-Id: 999999',
            'X-Company-Id: 999999',
        ]
    );
    t02HttpAssert($current['status'] === 200, 'bearer token reads current identity');
    t02HttpAssert(
        (int) $current['payload']['data']['company']['id'] === $companyId,
        'current identity ignores client company_id in query and headers'
    );
    $tokenHeaderCurrent = t02HttpRequest(
        $baseUrl,
        'GET',
        '/api/auth/current',
        [],
        ['Token: ' . $token]
    );
    t02HttpAssert($tokenHeaderCurrent['status'] === 200, 'Token header authenticates the enterprise identity');
    $currentJson = json_encode($current['payload'], JSON_UNESCAPED_UNICODE);
    foreach (['password_hash', 'Initial-Password-2026', $token] as $forbidden) {
        t02HttpAssert(strpos($currentJson, $forbidden) === false, 'current response excludes sensitive identity data');
    }

    $cookieOnly = t02HttpRequest(
        $baseUrl,
        'GET',
        '/api/auth/current',
        [],
        ['Cookie: token=' . $token]
    );
    t02HttpAssert($cookieOnly['status'] === 401, 'cookie-only authentication is rejected for CSRF safety');
    $queryCredentialResponse = t02HttpRequest(
        $baseUrl,
        'GET',
        '/api/auth/current?token=' . rawurlencode($token)
    );
    t02HttpAssert($queryCredentialResponse['status'] === 401, 'query token authentication is rejected');
    $formCredentialResponse = t02HttpRequest(
        $baseUrl,
        'POST',
        '/api/auth/logout',
        ['token' => $token]
    );
    t02HttpAssert($formCredentialResponse['status'] === 401, 'form token authentication is rejected');
    $wrongMethod = t02HttpRequest($baseUrl, 'POST', '/api/auth/current');
    t02HttpAssert($wrongMethod['status'] === 405, 'wrong HTTP method is rejected');

    $legacyCredentialValue = 'test-only-legacy-fastadmin-token';
    $pdo->prepare(
        'INSERT INTO `t02_http_user_token`'
        . ' (`token`,`user_id`,`purpose`,`createtime`,`expiretime`)'
        . ' VALUES (:token,:user_id,:purpose,:createtime,:expiretime)'
    )->execute([
        'token' => hash_hmac('ripemd160', $legacyCredentialValue, $tokenKey),
        'user_id' => $userId,
        'purpose' => 'legacy',
        'createtime' => time(),
        'expiretime' => time() + 3600,
    ]);
    $legacySession = t02HttpRequest(
        $baseUrl,
        'GET',
        '/api/auth/current',
        [],
        ['Authorization: Bearer ' . $legacyCredentialValue]
    );
    t02HttpAssert($legacySession['status'] === 401, 'legacy FastAdmin token is rejected by enterprise auth');

    $pdo->prepare('UPDATE `t02_http_user` SET `status`=:status WHERE `id`=:id')
        ->execute(['status' => 'disabled', 'id' => $userId]);
    $disabled = t02HttpRequest(
        $baseUrl,
        'GET',
        '/api/auth/current',
        [],
        ['Authorization: Bearer ' . $token]
    );
    t02HttpAssert($disabled['status'] === 401, 'disabling a logged-in user invalidates the old token');
    $pdo->prepare('UPDATE `t02_http_user` SET `status`=:status WHERE `id`=:id')
        ->execute(['status' => 'normal', 'id' => $userId]);

    $companySession = t02HttpRequest($baseUrl, 'POST', '/api/auth/login', [
        'account' => 't02-owner',
        'password' => 'Initial-Password-2026',
    ]);
    $companyToken = (string) ($companySession['payload']['data']['token'] ?? '');
    $pdo->prepare('UPDATE `t02_http_companies` SET `status`=:status WHERE `id`=:id')
        ->execute(['status' => 'disabled', 'id' => $companyId]);
    $disabledCompany = t02HttpRequest(
        $baseUrl,
        'GET',
        '/api/auth/current',
        [],
        ['Authorization: Bearer ' . $companyToken]
    );
    t02HttpAssert($disabledCompany['status'] === 401, 'disabling a company invalidates its old token');
    $pdo->prepare('UPDATE `t02_http_companies` SET `status`=:status WHERE `id`=:id')
        ->execute(['status' => 'normal', 'id' => $companyId]);

    $sessionA = t02HttpRequest($baseUrl, 'POST', '/api/auth/login', [
        'account' => 't02-owner',
        'password' => 'Initial-Password-2026',
    ]);
    $sessionB = t02HttpRequest($baseUrl, 'POST', '/api/auth/login', [
        'account' => 't02-owner',
        'password' => 'Initial-Password-2026',
    ]);
    $tokenA = (string) ($sessionA['payload']['data']['token'] ?? '');
    $tokenB = (string) ($sessionB['payload']['data']['token'] ?? '');
    t02HttpAssert($tokenA !== '' && $tokenB !== '' && $tokenA !== $tokenB, 'each login gets a fresh token');

    $cookiePasswordChange = t02HttpRequest(
        $baseUrl,
        'POST',
        '/api/auth/change-password',
        [
            'current_password' => 'Initial-Password-2026',
            'new_password' => 'Replacement-Password-2026',
        ],
        ['Cookie: token=' . $tokenA]
    );
    t02HttpAssert($cookiePasswordChange['status'] === 401, 'cookie-only password change is rejected');

    $changed = t02HttpRequest(
        $baseUrl,
        'POST',
        '/api/auth/change-password',
        [
            'current_password' => 'Initial-Password-2026',
            'new_password' => 'Replacement-Password-2026',
            'company_id' => 999999,
        ],
        ['Authorization: Bearer ' . $tokenA]
    );
    t02HttpAssert(
        $changed['status'] === 200,
        'password change succeeds status=' . $changed['status']
            . ' code=' . (string) ($changed['payload']['code'] ?? 'missing')
            . ' msg=' . (string) ($changed['payload']['msg'] ?? 'missing')
    );
    $storedHash = (string) $pdo->query(
        'SELECT `password_hash` FROM `t02_http_user` WHERE `id`=' . $userId
    )->fetchColumn();
    t02HttpAssert($storedHash !== 'Replacement-Password-2026', 'database never stores plaintext password');
    t02HttpAssert(
        password_verify('Replacement-Password-2026', $storedHash),
        'database stores a framework-recognized one-way password hash'
    );
    foreach ([$tokenA, $tokenB] as $oldToken) {
        $oldSession = t02HttpRequest(
            $baseUrl,
            'GET',
            '/api/auth/current',
            [],
            ['Authorization: Bearer ' . $oldToken]
        );
        t02HttpAssert($oldSession['status'] === 401, 'password change revokes every old session');
    }

    $oldCredentialResponse = t02HttpRequest($baseUrl, 'POST', '/api/auth/login', [
        'account' => 't02-owner',
        'password' => 'Initial-Password-2026',
    ]);
    t02HttpAssert($oldCredentialResponse['status'] === 401, 'old password no longer logs in');
    $newCredentialResponse = t02HttpRequest($baseUrl, 'POST', '/api/auth/login', [
        'account' => 't02-owner',
        'password' => 'Replacement-Password-2026',
    ]);
    $newToken = (string) ($newCredentialResponse['payload']['data']['token'] ?? '');
    t02HttpAssert($newCredentialResponse['status'] === 200 && $newToken !== '', 'new password logs in');

    $logout = t02HttpRequest(
        $baseUrl,
        'POST',
        '/api/auth/logout',
        [],
        ['Authorization: Bearer ' . $newToken]
    );
    t02HttpAssert($logout['status'] === 200, 'logout succeeds');
    $afterLogout = t02HttpRequest(
        $baseUrl,
        'GET',
        '/api/auth/current',
        [],
        ['Authorization: Bearer ' . $newToken]
    );
    t02HttpAssert($afterLogout['status'] === 401, 'logged-out token cannot access protected endpoints');

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        t02HttpRequest(
            $baseUrl,
            'POST',
            '/api/auth/login',
            ['account' => 'rate-limit-target', 'password' => 'wrong-password'],
            ['X-Forwarded-For: 127.0.0.' . (70 + $attempt)]
        );
    }
    $throttled = t02HttpRequest(
        $baseUrl,
        'POST',
        '/api/auth/login',
        ['account' => 'rate-limit-target', 'password' => 'wrong-password'],
        ['X-Forwarded-For: 127.0.0.99']
    );
    t02HttpAssert(
        $throttled['status'] === 429,
        'sixth failed login is throttled despite spoofed forwarded IP values'
    );
    t02HttpAssert((int) $throttled['payload']['code'] === 1429, 'throttle uses stable error code');

    $expiryLogin = t02HttpRequest($baseUrl, 'POST', '/api/auth/login', [
        'account' => 't02-owner',
        'password' => 'Replacement-Password-2026',
    ]);
    $expiryToken = (string) ($expiryLogin['payload']['data']['token'] ?? '');
    $pdo->prepare('UPDATE `t02_http_user_token` SET `expiretime`=:expired WHERE `user_id`=:user_id')
        ->execute(['expired' => time() - 1, 'user_id' => $userId]);
    $expired = t02HttpRequest(
        $baseUrl,
        'GET',
        '/api/auth/current',
        [],
        ['Authorization: Bearer ' . $expiryToken]
    );
    t02HttpAssert($expired['status'] === 401, 'expired token is rejected');
} catch (Throwable $exception) {
    $failure = $exception instanceof T02HttpTestFailure
        ? $exception->getMessage()
        : 'HTTP test failed class=' . str_replace('\\', '.', get_class($exception))
            . ' code=' . (string) $exception->getCode()
            . ' file=' . basename($exception->getFile())
            . ' line=' . (string) $exception->getLine();
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }
    if ($pdo instanceof PDO) {
        foreach ($dropOrder as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $after = $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($before !== [] && $after !== $before) {
            $failure = 'database table list changed after T02 HTTP cleanup';
        }
    }
}

if ($failure !== null) {
    fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

fwrite(STDOUT, "PASS: T02 HTTP authentication, session, tenant-context, and security flows\n");

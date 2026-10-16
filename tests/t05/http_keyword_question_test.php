<?php

declare(strict_types=1);

if (getenv('GEO_T05_HTTP_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T05 keyword/question HTTP test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T05_HTTP_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T05_HTTP_TEST_PREFIX') !== 't05_http_'
) {
    fwrite(STDERR, "FAIL: T05 HTTP safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;

final class T05HttpFailure extends RuntimeException
{
}

function t05HttpAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T05HttpFailure($message);
    }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}

function t05HttpRequest(string $base, string $method, string $path, array $body = [], array $headers = []): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", array_merge([
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ], $headers)),
        'content' => $body === [] ? '' : http_build_query($body),
        'ignore_errors' => true,
        'timeout' => 8,
        'follow_location' => 0,
    ]]);
    $text = @file_get_contents($base . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches)) {
        $status = (int) $matches[1];
    }
    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => is_string($text) ? $text : '',
        'payload' => json_decode(is_string($text) ? $text : '', true),
    ];
}

function t05WaitForServer(string $base): void
{
    $deadline = microtime(true) + 10;
    do {
        if (@file_get_contents($base . '/api/version') !== false) {
            return;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    throw new T05HttpFailure('local HTTP server did not become ready');
}

$environment = parse_ini_file(ROOT_PATH . '.env', true, INI_SCANNER_RAW);
$database = is_array($environment) ? ($environment['database'] ?? []) : [];
$value = static function (string $key, $fallback) {
    $current = getenv($key);
    return $current === false ? $fallback : $current;
};
$host = (string) $value('GEO_DATABASE_HOSTNAME', $database['hostname'] ?? '');
$port = (int) $value('GEO_DATABASE_HOSTPORT', $database['hostport'] ?? 0);
$name = (string) $value('GEO_DATABASE_DATABASE', $database['database'] ?? '');
$user = (string) $value('GEO_DATABASE_USERNAME', $database['username'] ?? '');
$password = (string) $value('GEO_DATABASE_PASSWORD', $database['password'] ?? '');
$prefix = 't05_http_';
$tables = array_map(static function (string $table) use ($prefix): string {
    return $prefix . $table;
}, ['questions', 'keywords', 'company_profiles', 'audit_logs', 'geo_auth_throttles', 'user_token', 'user', 'companies', 'geo_async_jobs', 'geo_migrations']);
$pdo = null;
$process = null;
$pipes = [];
$failure = null;
$before = [];
$logOut = tempnam(sys_get_temp_dir(), 'geo-t05-http-out-');
$logErr = tempnam(sys_get_temp_dir(), 'geo-t05-http-err-');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $before = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        t05HttpAssert(!in_array($table, $before, true), 'HTTP namespace is empty: ' . $table);
    }
    $manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
    t05HttpAssert(count($manager->migrateThrough('202608010005_create_keywords_and_questions')) === 5, 'HTTP fixture applies T01 through T05');

    $now = time();
    $insertCompany = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'companies` (`name`,`status`,`createtime`,`updatetime`) VALUES (:name,:status,:created,:updated)'
    );
    $insertUser = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'user` (`company_id`,`username`,`password_hash`,`role`,`status`,`createtime`,`updatetime`)'
        . ' VALUES (:company,:username,:password,:role,:status,:created,:updated)'
    );
    $fixtures = [];
    foreach (['a', 'b'] as $suffix) {
        $insertCompany->execute(['name' => 'HTTP Company ' . strtoupper($suffix), 'status' => 'normal', 'created' => $now, 'updated' => $now]);
        $companyId = (int) $pdo->lastInsertId();
        $insertUser->execute([
            'company' => $companyId,
            'username' => 't05-http-owner-' . $suffix,
            'password' => password_hash('T05-Http-Test-2026', PASSWORD_DEFAULT),
            'role' => 'company_owner',
            'status' => 'normal',
            'created' => $now,
            'updated' => $now,
        ]);
        $fixtures[$suffix] = ['company_id' => $companyId, 'user_id' => (int) $pdo->lastInsertId()];
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    t05HttpAssert($socket !== false, 'reserve local HTTP port');
    $socketName = stream_socket_get_name($socket, false);
    fclose($socket);
    $httpPort = (int) substr(strrchr((string) $socketName, ':'), 1);
    $base = 'http://127.0.0.1:' . $httpPort;
    $serverEnvironment = array_merge(getenv(), [
        'GEO_APP_ENV' => 'testing',
        'GEO_APP_TOKEN_KEY' => 'test-only-t05-http-token-key-20260801',
        'GEO_COMPANY_CONTACT_KEY' => 'base64:' . base64_encode(random_bytes(32)),
        'GEO_DATABASE_TYPE' => 'mysql',
        'GEO_DATABASE_HOSTNAME' => $host,
        'GEO_DATABASE_HOSTPORT' => (string) $port,
        'GEO_DATABASE_DATABASE' => $name,
        'GEO_DATABASE_USERNAME' => $user,
        'GEO_DATABASE_PASSWORD' => $password,
        'GEO_DATABASE_PREFIX' => $prefix,
    ]);
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $httpPort, '-t', ROOT_PATH . 'public', ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $logOut, 'a'], 2 => ['file', $logErr, 'a']],
        $pipes,
        ROOT_PATH,
        $serverEnvironment,
        ['bypass_shell' => true]
    );
    t05HttpAssert(is_resource($process), 'start isolated HTTP server');
    t05WaitForServer($base);

    $tokens = [];
    foreach (['a', 'b'] as $suffix) {
        $login = t05HttpRequest($base, 'POST', '/api/auth/login', [
            'account' => 't05-http-owner-' . $suffix,
            'password' => 'T05-Http-Test-2026',
        ]);
        t05HttpAssert($login['status'] === 200, 'login ' . strtoupper($suffix));
        $tokens[$suffix] = (string) ($login['payload']['data']['token'] ?? '');
    }
    $headerA = ['Authorization: Bearer ' . $tokens['a']];
    $headerB = ['Authorization: Bearer ' . $tokens['b']];

    t05HttpAssert(t05HttpRequest($base, 'GET', '/api/keyword/index')['status'] === 401, 'keyword list requires authentication');
    t05HttpAssert(
        t05HttpRequest($base, 'GET', '/api/keyword/index', [], ['Cookie: token=' . $tokens['a']])['status'] === 401,
        'cookie-only access is rejected for CSRF safety'
    );

    $keywordA = t05HttpRequest($base, 'POST', '/api/keyword/create', ['keyword' => '<script>globalThis.t05Xss=true</script>'], array_merge($headerA, [
        'Company-Id: ' . $fixtures['b']['company_id'],
        'X-Company-Id: ' . $fixtures['b']['company_id'],
    ]));
    t05HttpAssert($keywordA['status'] === 200, 'A creates an XSS-shaped keyword with forged tenant headers ignored');
    $keywordAId = (int) ($keywordA['payload']['data']['id'] ?? 0);
    $keywordB = t05HttpRequest($base, 'POST', '/api/keyword/create', ['keyword' => '<script>globalThis.t05Xss=true</script>'], $headerB);
    t05HttpAssert($keywordB['status'] === 200, 'B creates same keyword text');
    $keywordBId = (int) ($keywordB['payload']['data']['id'] ?? 0);
    t05HttpAssert(t05HttpRequest($base, 'POST', '/api/keyword/create', ['keyword' => ' <script>globalThis.t05Xss=true</script> '], $headerA)['status'] === 409, 'duplicate API request is rejected');
    t05HttpAssert(t05HttpRequest($base, 'POST', '/api/keyword/create', ['keyword' => 'spoofed', 'company_id' => $fixtures['b']['company_id']], $headerA)['status'] === 422, 'body company_id is rejected');
    t05HttpAssert(t05HttpRequest($base, 'GET', '/api/keyword/index?company_id=' . $fixtures['b']['company_id'], [], $headerA)['status'] === 422, 'query company_id cannot switch tenant');

    $foreignDetail = t05HttpRequest($base, 'GET', '/api/keyword/detail?id=' . $keywordBId, [], $headerA);
    $missingDetail = t05HttpRequest($base, 'GET', '/api/keyword/detail?id=999999', [], $headerA);
    t05HttpAssert($foreignDetail['status'] === 404 && $foreignDetail['payload']['msg'] === $missingDetail['payload']['msg'], 'cross-tenant keyword read is indistinguishable from missing');
    foreach ([
        'update' => ['id' => $keywordBId, 'keyword' => 'forged'],
        'status' => ['id' => $keywordBId, 'status' => 'disabled'],
        'delete' => ['id' => $keywordBId],
    ] as $action => $body) {
        t05HttpAssert(t05HttpRequest($base, 'POST', '/api/keyword/' . $action, $body, $headerA)['status'] === 404, 'A cannot ' . $action . ' B keyword');
    }
    foreach ([
        'update' => ['id' => $keywordAId, 'keyword' => 'not-applied', 'company_id' => $fixtures['b']['company_id']],
        'status' => ['id' => $keywordAId, 'status' => 'disabled', 'company_id' => $fixtures['b']['company_id']],
        'batch' => ['ids' => json_encode([$keywordAId]), 'status' => 'disabled', 'company_id' => $fixtures['b']['company_id']],
        'delete' => ['id' => $keywordAId, 'company_id' => $fixtures['b']['company_id']],
    ] as $action => $body) {
        t05HttpAssert(t05HttpRequest($base, 'POST', '/api/keyword/' . $action, $body, $headerA)['status'] === 422, 'keyword ' . $action . ' rejects protected tenant field');
    }
    t05HttpAssert(
        t05HttpRequest($base, 'GET', '/api/keyword/detail?id=' . $keywordAId . '&company_id=' . $fixtures['b']['company_id'], [], $headerA)['status'] === 422,
        'keyword detail rejects protected tenant query field'
    );
    t05HttpAssert(
        (t05HttpRequest($base, 'GET', '/api/keyword/detail?id=' . $keywordAId, [], $headerA)['payload']['data']['keyword'] ?? '')
            === '<script>globalThis.t05Xss=true</script>',
        'rejected keyword fields do not mutate the resource'
    );

    $questionA = t05HttpRequest($base, 'POST', '/api/question/create', [
        'keyword_id' => $keywordAId,
        'text' => '<img src=x onerror=globalThis.t05QuestionXss=true>',
    ], $headerA);
    t05HttpAssert($questionA['status'] === 200 && ($questionA['payload']['data']['source'] ?? '') === 'manual', 'A creates server-manual question');
    $questionAId = (int) ($questionA['payload']['data']['id'] ?? 0);
    t05HttpAssert(t05HttpRequest($base, 'POST', '/api/question/create', [
        'keyword_id' => $keywordAId,
        'text' => 'forged source',
        'source' => 'ai',
    ], $headerA)['status'] === 422, 'AI source forgery is rejected');
    t05HttpAssert(t05HttpRequest($base, 'POST', '/api/question/create', [
        'keyword_id' => $keywordBId,
        'text' => 'cross tenant',
    ], $headerA)['status'] === 404, 'A cannot attach question to B keyword');

    $keywordBQuestion = t05HttpRequest($base, 'POST', '/api/question/create', ['keyword_id' => $keywordBId, 'text' => 'B private'], $headerB);
    $questionBId = (int) ($keywordBQuestion['payload']['data']['id'] ?? 0);
    foreach (['detail' => 'GET', 'update' => 'POST', 'status' => 'POST', 'delete' => 'POST'] as $action => $method) {
        $path = '/api/question/' . $action . ($method === 'GET' ? '?id=' . $questionBId : '');
        $body = $action === 'update'
            ? ['id' => $questionBId, 'text' => 'forged']
            : ($action === 'status' ? ['id' => $questionBId, 'status' => 'disabled'] : ($method === 'POST' ? ['id' => $questionBId] : []));
        t05HttpAssert(t05HttpRequest($base, $method, $path, $body, $headerA)['status'] === 404, 'A cannot ' . $action . ' B question');
    }
    foreach ([
        'update' => ['id' => $questionAId, 'text' => 'not-applied', 'company_id' => $fixtures['b']['company_id']],
        'status' => ['id' => $questionAId, 'status' => 'disabled', 'source' => 'ai'],
        'batch' => ['ids' => json_encode([$questionAId]), 'status' => 'disabled', 'company_id' => $fixtures['b']['company_id']],
        'delete' => ['id' => $questionAId, 'source' => 'ai'],
    ] as $action => $body) {
        t05HttpAssert(t05HttpRequest($base, 'POST', '/api/question/' . $action, $body, $headerA)['status'] === 422, 'question ' . $action . ' rejects protected system field');
    }
    t05HttpAssert(
        t05HttpRequest($base, 'GET', '/api/question/detail?id=' . $questionAId . '&company_id=' . $fixtures['b']['company_id'], [], $headerA)['status'] === 422,
        'question detail rejects protected tenant query field'
    );
    t05HttpAssert(
        (t05HttpRequest($base, 'GET', '/api/question/detail?id=' . $questionAId, [], $headerA)['payload']['data']['text'] ?? '')
            === '<img src=x onerror=globalThis.t05QuestionXss=true>',
        'rejected question fields do not mutate the resource'
    );

    $batch = t05HttpRequest($base, 'POST', '/api/question/batch', ['ids' => json_encode([$questionAId, $questionBId]), 'status' => 'disabled'], $headerA);
    t05HttpAssert($batch['status'] === 404, 'cross-tenant question batch fails entirely');
    $questionAAfter = t05HttpRequest($base, 'GET', '/api/question/detail?id=' . $questionAId, [], $headerA);
    t05HttpAssert(($questionAAfter['payload']['data']['status'] ?? '') === 'normal', 'failed batch causes no partial update');

    $literalKeyword = t05HttpRequest($base, 'POST', '/api/keyword/create', ['keyword' => '100%_safe'], $headerA);
    t05HttpAssert($literalKeyword['status'] === 200, 'special-character keyword is created');
    $literalSearch = t05HttpRequest($base, 'GET', '/api/keyword/index?search=' . rawurlencode('%_'), [], $headerA);
    t05HttpAssert($literalSearch['status'] === 200 && (int) ($literalSearch['payload']['data']['total'] ?? 0) === 1, 'HTTP search escapes percent and underscore');
    $injection = t05HttpRequest($base, 'GET', '/api/keyword/index?search=' . rawurlencode("%' OR 1=1 --"), [], $headerA);
    t05HttpAssert($injection['status'] === 200 && (int) ($injection['payload']['data']['total'] ?? 0) === 0, 'SQL-injection-shaped search has no query effect');

    $page = t05HttpRequest($base, 'GET', '/index/keyword/index');
    t05HttpAssert($page['status'] === 200 && strpos($page['body'], '关键词与问题管理') !== false, 'enterprise keyword page is available');
    t05HttpAssert(stripos($page['body'], 'innerHTML') === false, 'page markup contains no unsafe innerHTML rendering');
    t05HttpAssert(count(array_filter($page['headers'], static function (string $header): bool {
        return stripos($header, 'Content-Security-Policy:') === 0;
    })) === 1, 'page sends CSP');

    $audits = $pdo->query(
        'SELECT `detail_json` FROM `' . $prefix . 'audit_logs`'
        . " WHERE `action` LIKE 'keyword_%' OR `action` LIKE 'question_%'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $auditText = implode(' ', $audits);
    t05HttpAssert(count($audits) >= 5, 'HTTP writes create audits');
    t05HttpAssert(strpos($auditText, 'globalThis.t05Xss') === false, 'audits omit complete keyword and question text');

    $pdo->prepare('UPDATE `' . $prefix . 'user` SET `status`=:status WHERE `id`=:id')->execute(['status' => 'disabled', 'id' => $fixtures['a']['user_id']]);
    t05HttpAssert(t05HttpRequest($base, 'GET', '/api/keyword/index', [], $headerA)['status'] === 401, 'disabled user cannot access content');
    $pdo->prepare('UPDATE `' . $prefix . 'companies` SET `status`=:status WHERE `id`=:id')->execute(['status' => 'disabled', 'id' => $fixtures['b']['company_id']]);
    t05HttpAssert(t05HttpRequest($base, 'GET', '/api/keyword/index', [], $headerB)['status'] === 401, 'disabled company cannot access content');

    proc_terminate($process);
    proc_close($process);
    $process = null;
    $serverLogs = (string) @file_get_contents($logOut) . (string) @file_get_contents($logErr);
    t05HttpAssert(strpos($serverLogs, 'globalThis.t05Xss') === false, 'ordinary server logs omit strategic request text');
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
        $after = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
        if ($before !== [] && $after !== $before) {
            $failure = new T05HttpFailure('HTTP cleanup changed unrelated database tables');
        }
    }
    if (is_string($logOut)) { @unlink($logOut); }
    if (is_string($logErr)) { @unlink($logErr); }
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . get_class($failure) . ' file=' . basename($failure->getFile())
        . ' line=' . $failure->getLine() . ' message=' . preg_replace(
            '/(?:password|token|secret|authorization|cookie|key)\s*[=:]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $failure->getMessage()
        ) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "RESULT: T05 HTTP auth, CRUD, tenant isolation, CSRF, XSS, SQL injection, audit, and disabled-state tests passed\n");

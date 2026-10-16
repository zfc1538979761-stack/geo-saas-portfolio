<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\api\controller\CompanyIntelligence;
use app\common\migration\MigrationManager;
use think\Env;

final class T19CompanyHttpFailure extends RuntimeException
{
}

function t19CompanyHttpAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new T19CompanyHttpFailure($message); }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}

function t19CompanyHttpRequest(string $base, string $method, string $path, array $data = [], array $headers = []): array
{
    $body = $method === 'GET' ? '' : http_build_query($data);
    $headerLines = array_merge(['Accept: application/json'], $headers);
    if ($body !== '') { $headerLines[] = 'Content-Type: application/x-www-form-urlencoded'; }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headerLines),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $raw = @file_get_contents($base . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', (string) ($responseHeaders[0] ?? ''), $match);
    return [
        'status' => isset($match[1]) ? (int) $match[1] : 0,
        'payload' => json_decode((string) $raw, true),
        'body' => (string) $raw,
    ];
}

function t19CompanyHttpCleanup(PDO $pdo, string $prefix): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $statement = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE ?');
    $statement->execute([str_replace(['_', '%'], ['\\_', '\\%'], $prefix) . '%']);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string) $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

try {
    t19CompanyHttpAssert(class_exists(CompanyIntelligence::class), 'company intelligence API controller exists');
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$processValue = static function (string $name, $fallback) {
    $value = getenv($name);
    return $value !== false ? $value : $fallback;
};
$host = (string) $processValue('GEO_DATABASE_HOSTNAME', Env::get('database.hostname', ''));
$port = (int) $processValue('GEO_DATABASE_HOSTPORT', Env::get('database.hostport', 0));
$database = (string) $processValue('GEO_DATABASE_DATABASE', Env::get('database.database', ''));
$username = (string) $processValue('GEO_DATABASE_USERNAME', Env::get('database.username', ''));
$password = (string) $processValue('GEO_DATABASE_PASSWORD', Env::get('database.password', ''));
if ($host !== '127.0.0.1' || $port === 3306 || preg_match('/_test$/D', $database) !== 1) {
    fwrite(STDERR, "FAIL: T19 company intelligence HTTP test requires an isolated non-3306 _test database\n");
    exit(1);
}

$prefix = 't19_company_http_';
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$httpDatabaseUser = 't19_company_http_db';
$httpDatabasePassword = 'test-only-t19-company-db-password';
$pdo->exec("CREATE USER IF NOT EXISTS `{$httpDatabaseUser}`@'127.0.0.1' IDENTIFIED BY '{$httpDatabasePassword}'");
$pdo->exec("GRANT ALL PRIVILEGES ON `{$database}`.* TO `{$httpDatabaseUser}`@'127.0.0.1'");
$server = null;
$pipes = [];
$failure = null;
$out = tempnam(sys_get_temp_dir(), 't19-company-http-out-');
$err = tempnam(sys_get_temp_dir(), 't19-company-http-err-');

try {
    t19CompanyHttpCleanup($pdo, $prefix);
    (new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix))->migrateThrough('202608220026_create_company_intelligence_onboarding');
    $now = time();
    $pdo->prepare("INSERT INTO `{$prefix}companies` (`name`,`status`,`createtime`,`updatetime`) VALUES ('HTTP企业','normal',?,?)")->execute([$now, $now]);
    $companyId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `{$prefix}user` (`company_id`,`username`,`password_hash`,`role`,`status`,`createtime`,`updatetime`) VALUES (?,?,?,'company_owner','normal',?,?)")
        ->execute([$companyId, 't19-company-http', password_hash('T19-Company-Http-2026', PASSWORD_DEFAULT), $now, $now]);

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    t19CompanyHttpAssert($socket !== false, 'reserve company intelligence HTTP port');
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    $httpPort = (int) substr(strrchr((string) $name, ':'), 1);
    $base = 'http://127.0.0.1:' . $httpPort;
    $environment = array_merge(getenv(), [
        'GEO_APP_ENV' => 'testing',
        'GEO_APP_TOKEN_KEY' => 'test-only-t19-company-http-token-key',
        'GEO_COMPANY_CONTACT_KEY' => 'base64:' . base64_encode(random_bytes(32)),
        'GEO_DATABASE_HOSTNAME' => $host,
        'GEO_DATABASE_HOSTPORT' => (string) $port,
        'GEO_DATABASE_DATABASE' => $database,
        'GEO_DATABASE_USERNAME' => $httpDatabaseUser,
        'GEO_DATABASE_PASSWORD' => $httpDatabasePassword,
        'GEO_DATABASE_PREFIX' => $prefix,
        'GEO_COMPANY_DISCOVERY_DRIVER' => 'responses_web_search',
        'GEO_COMPANY_DISCOVERY_WEB_SEARCH_ENABLED' => 'false',
        'GEO_COMPANY_DISCOVERY_EXTERNAL_CODE' => 'ToolNotOpen',
        'GEO_AI_PROVIDER' => 'mock',
    ]);
    $server = proc_open([
        PHP_BINARY, '-S', '127.0.0.1:' . $httpPort, '-t', ROOT_PATH . 'public',
        ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'router.php',
    ], [0 => ['pipe', 'r'], 1 => ['file', $out, 'a'], 2 => ['file', $err, 'a']], $pipes, ROOT_PATH, $environment, ['bypass_shell' => true]);
    t19CompanyHttpAssert(is_resource($server), 'start company intelligence HTTP server');
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        if (t19CompanyHttpRequest($base, 'GET', '/index/enterprise/index')['status'] === 200) { $ready = true; break; }
        usleep(100000);
    }
    t19CompanyHttpAssert($ready, 'company intelligence HTTP server becomes ready');

    $unauthenticated = t19CompanyHttpRequest($base, 'POST', '/api/company_intelligence/discover', ['company_name' => 'HTTP企业', 'region' => '上海市']);
    t19CompanyHttpAssert(
        $unauthenticated['status'] === 401,
        'company discovery requires enterprise authentication status=' . $unauthenticated['status']
        . ' body=' . substr($unauthenticated['body'], 0, 300)
    );
    $login = t19CompanyHttpRequest($base, 'POST', '/api/auth/login', ['account' => 't19-company-http', 'password' => 'T19-Company-Http-2026']);
    $token = (string) ($login['payload']['data']['token'] ?? '');
    t19CompanyHttpAssert($login['status'] === 200 && $token !== '', 'company intelligence test login succeeds');
    $headers = ['Authorization: Bearer ' . $token];
    $blocked = t19CompanyHttpRequest($base, 'POST', '/api/company_intelligence/discover', ['company_name' => 'HTTP企业', 'region' => '上海市'], $headers);
    t19CompanyHttpAssert(
        $blocked['status'] === 503
        && ($blocked['payload']['data']['error_category'] ?? null) === 'external_capability_unavailable'
        && ($blocked['payload']['data']['external_code'] ?? null) === 'ToolNotOpen'
        && ($blocked['payload']['data']['network_executed'] ?? true) === false,
        'ToolNotOpen API returns explicit safe external capability state status=' . $blocked['status']
        . ' body=' . substr($blocked['body'], 0, 500)
    );
    t19CompanyHttpAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}company_discovery_candidates`")->fetchColumn() === 0,
        'ToolNotOpen HTTP flow stores no fabricated candidates'
    );
    $page = t19CompanyHttpRequest($base, 'GET', '/index/enterprise/index');
    t19CompanyHttpAssert(
        strpos($page['body'], 'company-discovery-form') !== false
        && strpos($page['body'], 'company-candidate-list') !== false
        && strpos($page['body'], 'company-draft-form') !== false,
        'enterprise page exposes the minimal company intelligence onboarding flow'
    );
    $enterpriseScript = (string) file_get_contents(ROOT_PATH . 'public/assets/js/frontend/enterprise.js');
    t19CompanyHttpAssert(
        strpos($enterpriseScript, "input.disabled = draft.status === 'verified'") !== false
        && strpos($enterpriseScript, "el('company-draft-confirm').hidden = draft.status === 'verified'") !== false,
        'verified company profile drafts are rendered read-only instead of accepting ignored edits'
    );

    fwrite(STDOUT, "PASS: T19 company intelligence HTTP and page integration\n");
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    try { t19CompanyHttpCleanup($pdo, $prefix); } catch (Throwable $ignored) {}
    @unlink($out); @unlink($err);
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . get_class($failure) . ' ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}

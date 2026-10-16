<?php

declare(strict_types=1);

if (getenv('GEO_T05_PERFORMANCE_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T05 performance test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T05_PERFORMANCE_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T05_PERFORMANCE_TEST_PREFIX') !== 't05_performance_'
) {
    fwrite(STDERR, "FAIL: T05 performance safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\content\KeywordQuestionService;
use app\common\migration\MigrationManager;
use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantResourceCatalog;

final class T05PerformanceFailure extends RuntimeException
{
}

function t05PerformanceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T05PerformanceFailure($message);
    }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
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
$prefix = 't05_performance_';
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name), $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
]);
$tables = array_map(static function (string $table) use ($prefix): string { return $prefix . $table; },
    ['questions', 'keywords', 'company_profiles', 'audit_logs', 'geo_auth_throttles', 'user_token', 'user', 'companies', 'geo_async_jobs', 'geo_migrations']);
$failure = null;

try {
    $manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
    t05PerformanceAssert(count($manager->migrateThrough('202608010005_create_keywords_and_questions')) === 5, 'performance fixture applies T01 through T05');
    $now = time();
    $pdo->prepare('INSERT INTO `' . $prefix . 'companies` (`name`,`status`,`createtime`,`updatetime`) VALUES (\'Performance Company\',\'normal\',:created,:updated)')->execute(['created' => $now, 'updated' => $now]);
    $companyId = (int) $pdo->lastInsertId();
    $keywordInsert = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'keywords` (`company_id`,`keyword`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`)'
        . ' VALUES (:company,:keyword,:status,1,1,:created,:updated)'
    );
    for ($position = 1; $position <= 300; $position++) {
        $keywordInsert->execute([
            'company' => $companyId,
            'keyword' => 'Performance Keyword ' . $position,
            'status' => $position % 2 === 0 ? 'normal' : 'disabled',
            'created' => $now + $position,
            'updated' => $now + $position,
        ]);
    }
    $keywordId = (int) $pdo->query(
        "SELECT MIN(`id`) FROM `" . $prefix . "keywords` WHERE `status`='normal'"
    )->fetchColumn();
    $questionInsert = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'questions` (`company_id`,`keyword_id`,`text`,`text_hash`,`source`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`)'
        . ' VALUES (:company,:keyword,:text,:hash,\'manual\',:status,1,1,:created,:updated)'
    );
    for ($position = 1; $position <= 120; $position++) {
        $text = 'Performance Question ' . $position;
        $questionInsert->execute([
            'company' => $companyId, 'keyword' => $keywordId, 'text' => $text,
            'hash' => hash('sha256', strtolower($text), true), 'status' => 'normal',
            'created' => $now + $position, 'updated' => $now + $position,
        ]);
    }

    $keywordPlan = $pdo->query(
        'EXPLAIN SELECT * FROM `' . $prefix . 'keywords` WHERE `company_id`=' . $companyId
        . " AND `status`='normal' AND `deletetime` IS NULL ORDER BY `createtime` DESC,`id` DESC LIMIT 20"
    )->fetch(PDO::FETCH_ASSOC);
    t05PerformanceAssert(
        is_array($keywordPlan) && strpos((string) ($keywordPlan['possible_keys'] ?? ''), 'idx_keywords_company_status_created') !== false,
        'keyword list EXPLAIN exposes the tenant/status/order index'
    );
    $questionPlan = $pdo->query(
        'EXPLAIN SELECT * FROM `' . $prefix . 'questions` WHERE `company_id`=' . $companyId
        . ' AND `keyword_id`=' . $keywordId
        . " AND `status`='normal' AND `deletetime` IS NULL ORDER BY `createtime` DESC,`id` DESC LIMIT 20"
    )->fetch(PDO::FETCH_ASSOC);
    t05PerformanceAssert(
        is_array($questionPlan) && strpos((string) ($questionPlan['possible_keys'] ?? ''), 'idx_questions_company_keyword_status') !== false,
        'question list EXPLAIN exposes the tenant/keyword/status/order index'
    );

    $audit = new PdoAuditLogger($pdo, $prefix);
    $service = new KeywordQuestionService(
        $pdo,
        new TenantDataGateway($pdo, TenantResourceCatalog::registry($prefix), $audit),
        $audit
    );
    $context = TenantContext::enterprise(1, $companyId, 't05_performance');
    $beforeQuestions = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value'];
    $keywordPage = $service->keywordPage(['per_page' => 100], $context);
    $afterQuestions = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value'];
    t05PerformanceAssert(count($keywordPage['items']) === 100, 'keyword page returns 100 items');
    t05PerformanceAssert($afterQuestions - $beforeQuestions <= 3, 'keyword page query count is constant and has no N+1');

    $beforeQuestions = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value'];
    $questionPage = $service->questionPage($keywordId, ['per_page' => 100], $context);
    $afterQuestions = (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value'];
    t05PerformanceAssert(count($questionPage['items']) === 100, 'question page returns 100 items');
    t05PerformanceAssert($afterQuestions - $beforeQuestions <= 4, 'question page query count is constant and has no N+1');
    t05PerformanceAssert(
        count($service->activeQuestions($keywordId, $context)) === 120,
        'internal active question list paginates beyond the public page-size cap'
    );
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) { $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`'); }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . get_class($failure) . ': ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "RESULT: T05 EXPLAIN indexes and constant-query list tests passed\n");

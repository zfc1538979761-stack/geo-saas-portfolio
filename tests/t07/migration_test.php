<?php

declare(strict_types=1);

if (getenv('GEO_T07_MIGRATION_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T07 migration test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T07_MIGRATION_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T07_MIGRATION_TEST_PREFIX') !== 't07_migration_'
) {
    fwrite(STDERR, "FAIL: T07 migration safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;

final class T07MigrationFailure extends RuntimeException {}

function t07MigrationAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new T07MigrationFailure($message); }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}

$value = static function (string $name, $fallback = '') {
    $current = getenv($name);
    return $current === false ? $fallback : $current;
};
$host = (string) $value('GEO_DATABASE_HOSTNAME');
$port = (int) $value('GEO_DATABASE_HOSTPORT');
$name = (string) $value('GEO_DATABASE_DATABASE');
$user = (string) $value('GEO_DATABASE_USERNAME');
$password = (string) $value('GEO_DATABASE_PASSWORD');
if (
    $host !== '127.0.0.1' || $port < 1 || $port === 3306
    || $name !== (string) getenv('GEO_T07_MIGRATION_TEST_DATABASE')
    || preg_match('/_test(?:_[A-Za-z0-9]+)?$/D', $name) !== 1
) {
    fwrite(STDERR, "FAIL: T07 migration requires an explicit isolated non-3306 test database\n");
    exit(1);
}

$prefix = 't07_migration_';
$baseTables = [
    'article_version_knowledge', 'article_versions', 'articles', 'prompt_templates',
    'knowledge_items', 'oss_file_indexes', 'questions', 'keywords', 'company_profiles',
    'audit_logs', 'geo_auth_throttles', 'user_token', 'user', 'companies', 'geo_async_jobs',
];
$t07Tables = ['ai_calls', 'ai_providers'];
$t08Tables = ['suppliers', 'channels'];
$tables = array_map(static fn(string $table): string => $prefix . $table, array_merge($t07Tables, $t08Tables, $baseTables, ['geo_migrations']));
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);
$before = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
$failure = null;

try {
    foreach ($tables as $table) { t07MigrationAssert(!in_array($table, $before, true), 'T07 namespace starts empty: ' . $table); }
    $manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
    $applied = $manager->migrateThrough('202608050008_create_suppliers_and_channels');
    t07MigrationAssert($applied !== [], 'empty namespace applies T01 through T08');
    t07MigrationAssert(end($applied) === '202608050008_create_suppliers_and_channels', 'fixture stops at the T08 migration');

    foreach ($t07Tables as $table) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
        $statement->execute(['table' => $prefix . $table]);
        t07MigrationAssert((int) $statement->fetchColumn() === 1, 'T07 table exists: ' . $table);
    }
    $providers = $pdo->query('SELECT `code` FROM `' . $prefix . 'ai_providers` ORDER BY `code`')->fetchAll(PDO::FETCH_COLUMN);
    t07MigrationAssert($providers === ['doubao_ark', 'mock'], 'provider catalog seeds public codes only');

    $columns = $pdo->prepare(
        'SELECT COLUMN_NAME,IS_NULLABLE,DATA_TYPE FROM information_schema.COLUMNS'
        . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
    );
    $columns->execute(['table' => $prefix . 'geo_async_jobs']);
    $jobColumns = [];
    foreach ($columns->fetchAll() as $column) { $jobColumns[$column['COLUMN_NAME']] = $column; }
    foreach (['actor_id', 'provider_code', 'input_snapshot_json', 'started_at', 'lease_expires_at', 'failed_at', 'cancelled_at', 'result_article_id', 'result_version_id', 'last_error_message'] as $column) {
        t07MigrationAssert(isset($jobColumns[$column]), 'jobs fact has T07 column: ' . $column);
    }

    $columns->execute(['table' => $prefix . 'ai_calls']);
    $callColumns = [];
    foreach ($columns->fetchAll() as $column) { $callColumns[$column['COLUMN_NAME']] = $column; }
    foreach (['company_id', 'job_id', 'provider_id', 'provider_code', 'model_name', 'attempt_no', 'input_tokens', 'output_tokens', 'cost_amount', 'currency', 'latency_ms', 'provider_request_id', 'status', 'error_category'] as $column) {
        t07MigrationAssert(isset($callColumns[$column]), 'ai_calls records field: ' . $column);
    }
    t07MigrationAssert(($callColumns['cost_amount']['IS_NULLABLE'] ?? '') === 'YES', 'unconfirmed AI cost is nullable');

    $articleColumn = $pdo->prepare(
        'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()'
        . ' AND TABLE_NAME=:table AND COLUMN_NAME=\'ai_call_id\''
    );
    $articleColumn->execute(['table' => $prefix . 'article_versions']);
    t07MigrationAssert($articleColumn->fetchColumn() === 'YES', 'article versions gain nullable AI call provenance');

    $tenantLeading = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()'
        . ' AND TABLE_NAME=:table AND COLUMN_NAME=\'company_id\' AND SEQ_IN_INDEX=1'
    );
    foreach (['ai_calls', 'geo_async_jobs'] as $table) {
        $tenantLeading->execute(['table' => $prefix . $table]);
        t07MigrationAssert((int) $tenantLeading->fetchColumn() > 0, 'tenant-leading index protects: ' . $table);
    }

    $foreignKeys = $pdo->query(
        'SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME'
        . ' FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()'
        . " AND TABLE_NAME LIKE 't07_migration\\_%' ESCAPE '\\\\' AND REFERENCED_TABLE_NAME IS NOT NULL"
        . ' ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION'
    )->fetchAll();
    $foreignJson = (string) json_encode($foreignKeys, JSON_UNESCAPED_SLASHES);
    foreach (['companies', 'geo_async_jobs', 'ai_providers', 'ai_calls', 'articles', 'article_versions'] as $target) {
        t07MigrationAssert(strpos($foreignJson, $prefix . $target) !== false, 'T07 foreign key target exists: ' . $target);
    }

    $pdo->exec('UPDATE `' . $prefix . 'geo_migrations` SET `batch`=1');
    $pdo->exec(
        'UPDATE `' . $prefix . 'geo_migrations` SET `batch`=2'
        . " WHERE `migration`='202608040007_create_ai_generation'"
    );
    t07MigrationAssert($manager->rollback(1) === ['202608040007_create_ai_generation'], 'latest rollback removes only T07');
    foreach (['articles', 'article_versions', 'geo_async_jobs'] as $table) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
        $statement->execute(['table' => $prefix . $table]);
        t07MigrationAssert((int) $statement->fetchColumn() === 1, 'T07 rollback preserves T01-T06 table: ' . $table);
    }
    $articleColumn->execute(['table' => $prefix . 'article_versions']);
    t07MigrationAssert($articleColumn->fetchColumn() === false, 'T07 rollback removes only AI provenance column');
    t07MigrationAssert(
        $manager->migrateThrough('202608050008_create_suppliers_and_channels') === ['202608040007_create_ai_generation'],
        'T07 re-migrates after rollback'
    );
    t07MigrationAssert(($manager->status()['202608040007_create_ai_generation'] ?? null) === 'applied', 'T07 finishes applied');
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) { $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`'); }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

$after = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
if ($failure === null && $after !== $before) { $failure = new T07MigrationFailure('T07 cleanup changed unrelated database tables.'); }
if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . get_class($failure) . ' file=' . basename($failure->getFile())
        . ' line=' . $failure->getLine() . ' message=' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "RESULT: T07 migrate, constraints, latest rollback, and re-migrate passed\n");

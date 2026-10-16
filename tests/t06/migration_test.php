<?php

declare(strict_types=1);

if (getenv('GEO_T06_MIGRATION_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T06 migration test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T06_MIGRATION_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T06_MIGRATION_TEST_PREFIX') !== 't06_migration_'
) {
    fwrite(STDERR, "FAIL: T06 migration safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;

final class T06MigrationFailure extends RuntimeException
{
}

function t06MigrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T06MigrationFailure($message);
    }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}

$env = parse_ini_file(ROOT_PATH . '.env', true, INI_SCANNER_RAW);
$database = is_array($env) ? ($env['database'] ?? []) : [];
$value = static function (string $name, $fallback) {
    $current = getenv($name);
    return $current === false ? $fallback : $current;
};
$host = (string) $value('GEO_DATABASE_HOSTNAME', $database['hostname'] ?? '');
$port = (int) $value('GEO_DATABASE_HOSTPORT', $database['hostport'] ?? 0);
$name = (string) $value('GEO_DATABASE_DATABASE', $database['database'] ?? '');
$user = (string) $value('GEO_DATABASE_USERNAME', $database['username'] ?? '');
$password = (string) $value('GEO_DATABASE_PASSWORD', $database['password'] ?? '');
$expectedName = (string) getenv('GEO_T06_MIGRATION_TEST_DATABASE');
if (
    $host !== '127.0.0.1' || $port < 1 || $port === 3306 || $name === '' || $name !== $expectedName
    || preg_match('/_(?:test|regression)(?:_[A-Za-z0-9]+)?$/D', $name) !== 1 || $user === ''
) {
    fwrite(STDERR, "FAIL: T06 migration requires an explicit isolated non-3306 test database\n");
    exit(1);
}

$prefix = 't06_migration_';
$businessTables = [
    'article_version_knowledge', 'article_versions', 'articles', 'prompt_templates',
    'knowledge_items', 'oss_file_indexes', 'questions', 'keywords', 'company_profiles',
    'audit_logs', 'geo_auth_throttles', 'user_token', 'user', 'companies', 'geo_async_jobs',
];
$tables = array_map(static function (string $table) use ($prefix): string {
    return $prefix . $table;
}, array_merge($businessTables, ['geo_migrations']));
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);
$before = $pdo->query(
    'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
)->fetchAll(PDO::FETCH_COLUMN);
$failure = null;

try {
    foreach ($tables as $table) {
        t06MigrationAssert(!in_array($table, $before, true), 'T06 migration namespace starts empty');
    }
    $manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
    $applied = $manager->migrateThrough('202608010006_create_content_version_foundation');
    t06MigrationAssert(count($applied) === 6, 'empty namespace applies T01 through T06');
    t06MigrationAssert(end($applied) === '202608010006_create_content_version_foundation', 'T06 is the sixth migration');

    foreach (['oss_file_indexes', 'knowledge_items', 'prompt_templates', 'articles', 'article_versions', 'article_version_knowledge'] as $table) {
        t06MigrationAssert(in_array($prefix . $table, $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()'
        )->fetchAll(PDO::FETCH_COLUMN), true), 'T06 table exists: ' . $table);
        $leading = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()'
            . ' AND TABLE_NAME=:table AND COLUMN_NAME=\'company_id\' AND SEQ_IN_INDEX=1'
        );
        $leading->execute(['table' => $prefix . $table]);
        t06MigrationAssert((int) $leading->fetchColumn() > 0, 'tenant-leading index exists: ' . $table);
    }

    $current = $pdo->prepare(
        'SELECT IS_NULLABLE,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()'
        . ' AND TABLE_NAME=:table AND COLUMN_NAME=\'current_version_id\''
    );
    $current->execute(['table' => $prefix . 'articles']);
    $currentColumn = $current->fetch();
    t06MigrationAssert(
        ($currentColumn['IS_NULLABLE'] ?? '') === 'YES',
        'article pointer permits only the in-transaction bootstrap state before version 1 exists'
    );

    $foreignKeys = $pdo->query(
        'SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME'
        . ' FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()'
        . " AND TABLE_NAME LIKE 't06_migration\\_%' ESCAPE '\\\\' AND REFERENCED_TABLE_NAME IS NOT NULL"
        . ' ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION'
    )->fetchAll();
    $foreignJson = (string) json_encode($foreignKeys, JSON_UNESCAPED_SLASHES);
    foreach (['companies', 'keywords', 'questions', 'oss_file_indexes', 'prompt_templates', 'articles', 'article_versions', 'knowledge_items'] as $target) {
        t06MigrationAssert(strpos($foreignJson, $prefix . $target) !== false, 'foreign key target exists: ' . $target);
    }

    $questionUnique = $pdo->prepare(
        'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS'
        . ' WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND INDEX_NAME=\'uq_questions_company_id_keyword\''
    );
    $questionUnique->execute(['table' => $prefix . 'questions']);
    t06MigrationAssert(
        $questionUnique->fetchColumn() === 'company_id,id,keyword_id',
        'T06 adds the question relationship key needed by article integrity'
    );

    $pdo->exec('UPDATE `' . $prefix . 'geo_migrations` SET `batch`=1');
    $pdo->exec(
        'UPDATE `' . $prefix . 'geo_migrations` SET `batch`=2'
        . " WHERE `migration`='202608010006_create_content_version_foundation'"
    );
    t06MigrationAssert(
        $manager->rollback(1) === ['202608010006_create_content_version_foundation'],
        'latest rollback removes only T06'
    );
    t06MigrationAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'questions`')->fetchColumn() === 0,
        'T06 rollback preserves the T05 question table'
    );
    $questionUnique->execute(['table' => $prefix . 'questions']);
    t06MigrationAssert($questionUnique->fetchColumn() === null, 'T06 rollback removes only its added question key');
    t06MigrationAssert(
        $manager->migrateThrough('202608010006_create_content_version_foundation') === ['202608010006_create_content_version_foundation'],
        'T06 re-migrates after rollback'
    );
    t06MigrationAssert(
        ($manager->status()['202608010006_create_content_version_foundation'] ?? null) === 'applied',
        'T06 finishes applied'
    );
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

$after = $pdo->query(
    'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
)->fetchAll(PDO::FETCH_COLUMN);
if ($failure === null && $after !== $before) {
    $failure = new T06MigrationFailure('T06 migration cleanup changed unrelated database tables.');
}
if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . get_class($failure) . ' file=' . basename($failure->getFile())
        . ' line=' . $failure->getLine() . ' message=' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "RESULT: T06 migrate, constraints, rollback-only-T06, and re-migrate passed\n");

<?php

declare(strict_types=1);

if (getenv('GEO_T08_MIGRATION_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: T08 migration test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_T08_MIGRATION_TEST_ALLOW_SHARED_DATABASE') !== '1'
    || getenv('GEO_T08_MIGRATION_TEST_PREFIX') !== 't08_migration_'
) {
    fwrite(STDERR, "FAIL: T08 migration safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;

final class T08MigrationFailure extends RuntimeException
{
}

function t08MigrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T08MigrationFailure($message);
    }
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
    || $name !== (string) getenv('GEO_T08_MIGRATION_TEST_DATABASE')
    || preg_match('/_test(?:_[A-Za-z0-9]+)?$/D', $name) !== 1
) {
    fwrite(STDERR, "FAIL: T08 migration requires an explicit isolated non-3306 test database\n");
    exit(1);
}

$prefix = 't08_migration_';
$baseTables = [
    'article_version_knowledge', 'article_versions', 'articles', 'prompt_templates',
    'knowledge_items', 'oss_file_indexes', 'questions', 'keywords', 'company_profiles',
    'audit_logs', 'geo_auth_throttles', 'user_token', 'user', 'companies', 'geo_async_jobs',
    'ai_calls', 'ai_providers',
];
$t08Tables = ['suppliers', 'channels'];
$tables = array_map(static fn(string $table): string => $prefix . $table, array_merge($t08Tables, $baseTables, ['geo_migrations']));
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);
$before = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
$failure = null;

try {
    foreach ($tables as $table) {
        t08MigrationAssert(!in_array($table, $before, true), 'T08 namespace starts empty: ' . $table);
    }
    $manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
    $applied = $manager->migrateThrough('202608050008_create_suppliers_and_channels');
    t08MigrationAssert(count($applied) === 8, 'empty namespace applies T01 through T08');
    t08MigrationAssert(end($applied) === '202608050008_create_suppliers_and_channels', 'T08 is the eighth forward migration');

    foreach ($t08Tables as $table) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
        $statement->execute(['table' => $prefix . $table]);
        t08MigrationAssert((int) $statement->fetchColumn() === 1, 'T08 table exists: ' . $table);
    }

    $suppliers = $pdo->query('SELECT `code`,`display_name`,`status` FROM `' . $prefix . 'suppliers` ORDER BY `code`')->fetchAll();
    t08MigrationAssert(
        $suppliers === [['code' => 'mock', 'display_name' => 'Deterministic Mock', 'status' => 'normal']],
        'suppliers seeds only the mock supplier catalog row'
    );
    $channels = $pdo->query(
        'SELECT `code`,`category`,`status`,`supplier_confirmed` FROM `' . $prefix . 'channels` ORDER BY `code`'
    )->fetchAll();
    t08MigrationAssert(
        $channels === [
            ['code' => 'b2b', 'category' => 'b2b', 'status' => 'normal', 'supplier_confirmed' => 0],
            ['code' => 'influencer', 'category' => 'influencer', 'status' => 'normal', 'supplier_confirmed' => 0],
            ['code' => 'official_media', 'category' => 'official_media', 'status' => 'normal', 'supplier_confirmed' => 0],
        ],
        'channels seeds the three internal classifications without claiming supplier confirmation'
    );

    $unique = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()'
        . ' AND TABLE_NAME=:table AND COLUMN_NAME=\'code\' AND NON_UNIQUE=0'
    );
    foreach (['suppliers', 'channels'] as $table) {
        $unique->execute(['table' => $prefix . $table]);
        t08MigrationAssert((int) $unique->fetchColumn() === 1, 'unique code constraint exists: ' . $table);
    }

    try {
        $pdo->exec(
            'INSERT INTO `' . $prefix . 'channels`'
            . " (`code`,`name`,`category`,`status`,`supplier_confirmed`,`created_at`,`updated_at`)"
            . " VALUES ('official_media','Duplicate','official_media','normal',0,NOW(),NOW())"
        );
        throw new T08MigrationFailure('duplicate channel code was accepted');
    } catch (PDOException $expected) {
        t08MigrationAssert((int) ($expected->errorInfo[1] ?? 0) === 1062, 'database rejects duplicate channel code');
    }
    try {
        $pdo->exec(
            'INSERT INTO `' . $prefix . 'suppliers`'
            . " (`code`,`display_name`,`status`,`created_at`,`updated_at`)"
            . " VALUES ('mock','Duplicate','normal',NOW(),NOW())"
        );
        throw new T08MigrationFailure('duplicate supplier code was accepted');
    } catch (PDOException $expected) {
        t08MigrationAssert((int) ($expected->errorInfo[1] ?? 0) === 1062, 'database rejects duplicate supplier code');
    }

    $pdo->exec(
        'INSERT INTO `' . $prefix . 'channels`'
        . " (`code`,`name`,`category`,`status`,`supplier_confirmed`,`created_at`,`updated_at`)"
        . " VALUES ('official_media','Official Media','official_media','normal',0,NOW(),NOW())"
        . ' ON DUPLICATE KEY UPDATE `updated_at`=NOW()'
    );
    $pdo->exec(
        'INSERT INTO `' . $prefix . 'suppliers`'
        . " (`code`,`display_name`,`status`,`created_at`,`updated_at`)"
        . " VALUES ('mock','Deterministic Mock','normal',NOW(),NOW())"
        . ' ON DUPLICATE KEY UPDATE `updated_at`=NOW()'
    );
    t08MigrationAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'channels`')->fetchColumn() === 3,
        'channel seed re-initialization is idempotent'
    );
    t08MigrationAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'suppliers`')->fetchColumn() === 1,
        'supplier seed re-initialization is idempotent'
    );

    $pdo->exec('UPDATE `' . $prefix . 'geo_migrations` SET `batch`=1');
    $pdo->exec(
        'UPDATE `' . $prefix . 'geo_migrations` SET `batch`=2'
        . " WHERE `migration`='202608050008_create_suppliers_and_channels'"
    );
    t08MigrationAssert(
        $manager->rollback(1) === ['202608050008_create_suppliers_and_channels'],
        'latest rollback removes only T08'
    );
    foreach (['suppliers', 'channels'] as $table) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
        $statement->execute(['table' => $prefix . $table]);
        t08MigrationAssert((int) $statement->fetchColumn() === 0, 'T08 rollback removes table: ' . $table);
    }
    foreach (['ai_providers', 'ai_calls'] as $table) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
        $statement->execute(['table' => $prefix . $table]);
        t08MigrationAssert((int) $statement->fetchColumn() === 1, 'T08 rollback preserves T07 table: ' . $table);
    }
    t08MigrationAssert(
        $manager->migrateThrough('202608050008_create_suppliers_and_channels') === ['202608050008_create_suppliers_and_channels'],
        'T08 re-migrates after rollback'
    );
    t08MigrationAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'channels`')->fetchColumn() === 3,
        'T08 re-migrate restores the channel seed'
    );
    t08MigrationAssert(
        ($manager->status()['202608050008_create_suppliers_and_channels'] ?? null) === 'applied',
        'T08 finishes applied'
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

$after = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
if ($failure === null && $after !== $before) {
    $failure = new T08MigrationFailure('T08 migration cleanup changed unrelated database tables.');
}
if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . get_class($failure) . ' file=' . basename($failure->getFile())
        . ' line=' . $failure->getLine() . ' message=' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "RESULT: T08 migrate, unique constraints, idempotent seeds, rollback-only-T08, and re-migrate passed\n");

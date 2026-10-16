<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\Migration;
use app\common\supplier\MediaSyncService;

$prefix = getenv('GEO_T09_MIGRATION_TEST_TABLE_PREFIX');
if (!is_string($prefix) || trim($prefix) === '') {
    fwrite(STDERR, "SKIP: GEO_T09_MIGRATION_TEST_TABLE_PREFIX is required.\n");
    exit(0);
}
$prefix = trim($prefix);

$pdo = new \PDO(
    'mysql:host=127.0.0.1;port=33317;charset=utf8mb4',
    'root',
    getenv('GEO_MYSQL_ROOT_PASSWORD') ?: '',
    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
);

$database = getenv('GEO_MYSQL_TEST_DATABASE') ?: 'geo_migration_test';
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$database}`");

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "PASS: {$name}\n";
        $pass++;
    } catch (\Throwable $e) {
        echo "FAIL: {$name} - {$e->getMessage()}\n";
        $fail++;
    }
}

function runMigration(\PDO $pdo, string $file, string $prefix, string $direction): void {
    $migration = require $file;
    if ($migration instanceof Migration) {
        if ($direction === 'up') {
            $migration->up($pdo, $prefix);
        } else {
            $migration->down($pdo, $prefix);
        }
    }
}

$migrationsDir = ROOT_PATH . 'database/migrations';
$prevMigrations = [
    '202607300001_create_geo_async_jobs.php',
    '202607300002_create_company_identity.php',
    '202607300003_enforce_tenant_isolation.php',
    '202607300004_create_company_profiles.php',
    '202608010005_create_keywords_and_questions.php',
    '202608010006_create_content_version_foundation.php',
    '202608040007_create_ai_generation.php',
    '202608050008_create_suppliers_and_channels.php',
];

foreach ($prevMigrations as $migrationFile) {
    runMigration($pdo, $migrationsDir . '/' . $migrationFile, $prefix, 'up');
}

echo "T09 Migration Tests\n";
echo "==================\n";

test('migrate up creates media_resources and media_change_logs', function () use ($pdo, $prefix) {
    $migrationsDir = ROOT_PATH . 'database/migrations';
    runMigration($pdo, $migrationsDir . '/202608050009_create_media_resources.php', $prefix, 'up');
    $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(\PDO::FETCH_COLUMN);
    $mediaTable = $prefix . 'media_resources';
    $logTable = $prefix . 'media_change_logs';
    assert(in_array($mediaTable, $tables), "media_resources table missing");
    assert(in_array($logTable, $tables), "media_change_logs table missing");
});

test('media_resources has required columns', function () use ($pdo, $prefix) {
    $cols = $pdo->query("SHOW COLUMNS FROM `{$prefix}media_resources`")->fetchAll(\PDO::FETCH_ASSOC);
    $names = array_column($cols, 'Field');
    foreach (['id','supplier_id','external_id','channel_id','name','cost_price','status','attributes_json','last_synced_at'] as $col) {
        assert(in_array($col, $names), "Missing column: {$col}");
    }
});

test('(supplier_id, external_id) has unique constraint', function () use ($pdo, $prefix) {
    $keys = $pdo->query("SHOW INDEX FROM `{$prefix}media_resources` WHERE Key_name LIKE 'uq_media_supplier_external%'")->fetchAll();
    assert(count($keys) > 0, "Unique key missing");
});

test('rollback drops media_resources and media_change_logs', function () use ($pdo, $prefix) {
    $migrationsDir = ROOT_PATH . 'database/migrations';
    runMigration($pdo, $migrationsDir . '/202608050009_create_media_resources.php', $prefix, 'down');
    $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(\PDO::FETCH_COLUMN);
    assert(!in_array($prefix . 'media_resources', $tables), "media_resources still exists after rollback");
    assert(!in_array($prefix . 'media_change_logs', $tables), "media_change_logs still exists after rollback");
});

test('re-migrate restores tables', function () use ($pdo, $prefix) {
    $migrationsDir = ROOT_PATH . 'database/migrations';
    runMigration($pdo, $migrationsDir . '/202608050009_create_media_resources.php', $prefix, 'up');
    $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(\PDO::FETCH_COLUMN);
    assert(in_array($prefix . 'media_resources', $tables), "media_resources missing after re-migrate");
    assert(in_array($prefix . 'media_change_logs', $tables), "media_change_logs missing after re-migrate");
});

test('media_market supplier exists after migration', function () use ($pdo, $prefix) {
    $stmt = $pdo->prepare("SELECT code FROM `{$prefix}suppliers` WHERE code = 'media_market'");
    $stmt->execute();
    assert($stmt->fetchColumn() === 'media_market', "media_market supplier not seeded");
});

test('MediaSyncService logChange accepts string ids from PDO assoc', function () use ($pdo, $prefix) {
    $service = new MediaSyncService($pdo, $prefix);
    $ref = new ReflectionMethod(MediaSyncService::class, 'logChange');
    $ref->setAccessible(true);
    $ref->invoke($service, '123', 'name', 'old', 'new', 'sync-task-1');
    $count = $pdo->query("SELECT COUNT(*) FROM `{$prefix}media_change_logs` WHERE media_resource_id=123")->fetchColumn();
    assert((int) $count === 1, 'logChange must accept string media ids');
});

$pdo->exec("DROP DATABASE IF EXISTS `{$database}`");

echo "\n---\n";
echo "T09 Migration: {$pass} PASS, {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);

<?php

declare(strict_types=1);

if (getenv('GEO_MIGRATION_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: isolated migration test is not enabled\n");
    exit(0);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;

function migrationFail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

if (!class_exists(MigrationManager::class)) {
    migrationFail('MigrationManager class is missing');
}

$database = (string) getenv('GEO_MIGRATION_TEST_DATABASE');
if (preg_match('/^[A-Za-z][A-Za-z0-9_]*_test(?:_[A-Za-z0-9]+)?$/', $database) !== 1) {
    migrationFail('database safety gate rejected the configured test database');
}

$host = (string) getenv('GEO_MIGRATION_TEST_HOST');
$port = (int) getenv('GEO_MIGRATION_TEST_PORT');
$username = (string) getenv('GEO_MIGRATION_TEST_USERNAME');
$password = (string) getenv('GEO_MIGRATION_TEST_PASSWORD');
$migrationPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'geo_t01_migration_' . getmypid();

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
    $existing = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()"
        . " AND TABLE_NAME LIKE 'fa\\_%' ESCAPE '\\\\'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($existing as $table) {
        if (preg_match('/^fa_[A-Za-z0-9_]+$/', (string) $table) !== 1) {
            migrationFail('isolated migration cleanup rejected a table name');
        }
        $pdo->exec('DROP TABLE `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    if (!mkdir($migrationPath, 0700) && !is_dir($migrationPath)) {
        migrationFail('isolated T01 migration view could not be created');
    }
    if (!copy(
        ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations'
            . DIRECTORY_SEPARATOR . '202607300001_create_geo_async_jobs.php',
        $migrationPath . DIRECTORY_SEPARATOR . '202607300001_create_geo_async_jobs.php'
    )) {
        migrationFail('isolated T01 migration could not be copied');
    }
    $manager = new MigrationManager(
        $pdo,
        $migrationPath,
        'fa_'
    );

    $manager->migrate();
    $existsAfterUp = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables"
        . " WHERE table_schema = DATABASE() AND table_name = 'fa_geo_async_jobs'"
    )->fetchColumn();
    if ($existsAfterUp !== 1) {
        migrationFail('generic asynchronous job table is missing after migrate');
    }

    $manager->rollback(1);
    $existsAfterDown = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables"
        . " WHERE table_schema = DATABASE() AND table_name = 'fa_geo_async_jobs'"
    )->fetchColumn();
    if ($existsAfterDown !== 0) {
        migrationFail('generic asynchronous job table remains after rollback');
    }

    $manager->migrate();
    $existsAfterRemigrate = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables"
        . " WHERE table_schema = DATABASE() AND table_name = 'fa_geo_async_jobs'"
    )->fetchColumn();
    if ($existsAfterRemigrate !== 1) {
        migrationFail('generic asynchronous job table is missing after remigrate');
    }
} catch (Throwable $exception) {
    migrationFail(
        'migration integration failed class=' . str_replace('\\', '.', get_class($exception))
        . ' code=' . (string) $exception->getCode()
    );
}

if (is_dir($migrationPath)) {
    foreach (glob($migrationPath . DIRECTORY_SEPARATOR . '*.php') ?: [] as $path) {
        unlink($path);
    }
    rmdir($migrationPath);
}

fwrite(STDOUT, "PASS: isolated migration up/down/up\n");

<?php

declare(strict_types=1);

if (getenv('GEO_MIGRATION_NAMESPACE_TEST_ENABLE') !== '1') {
    fwrite(STDOUT, "SKIP: shared database migration namespace test is not enabled\n");
    exit(0);
}
if (
    getenv('GEO_APP_ENV') !== 'testing'
    || getenv('GEO_MIGRATION_NAMESPACE_TEST_ALLOW_SHARED_DATABASE') !== '1'
) {
    fwrite(STDERR, "FAIL: shared database migration namespace safety gate rejected the environment\n");
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\migration\MigrationManager;

$prefix = (string) getenv('GEO_MIGRATION_NAMESPACE_TEST_PREFIX');
if ($prefix !== 't01_acceptance_') {
    fwrite(STDERR, "FAIL: shared database migration prefix safety gate rejected the prefix\n");
    exit(1);
}

$database = (string) getenv('GEO_MIGRATION_NAMESPACE_TEST_DATABASE');
$host = (string) getenv('GEO_MIGRATION_NAMESPACE_TEST_HOST');
$port = (int) getenv('GEO_MIGRATION_NAMESPACE_TEST_PORT');
$username = (string) getenv('GEO_MIGRATION_NAMESPACE_TEST_USERNAME');
$password = (string) getenv('GEO_MIGRATION_NAMESPACE_TEST_PASSWORD');
if ($database === '' || $host === '' || $port < 1 || $username === '') {
    fwrite(STDERR, "FAIL: shared database migration configuration is incomplete\n");
    exit(1);
}

$pdo = null;
$before = [];
$failure = null;
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
    foreach ($before as $table) {
        if (strpos((string) $table, $prefix) === 0) {
            throw new RuntimeException('shared database migration namespace is not empty');
        }
    }

    $manager = new MigrationManager(
        $pdo,
        ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations',
        $prefix
    );
    $firstMigrationIds = $manager->migrate();
    if ($firstMigrationIds === []) {
        throw new RuntimeException('shared database migration did not apply any migrations');
    }

    $firstCreated = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 't01\\_acceptance\\_%'"
        . ' ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
    if (
        !in_array($prefix . 'geo_migrations', $firstCreated, true)
        || count($firstCreated) < 2
    ) {
        throw new RuntimeException('shared database migration did not create the expected isolated namespace');
    }

    $rolledBackIds = $manager->rollback(1);
    $expectedRollbackIds = array_reverse($firstMigrationIds);
    if ($rolledBackIds !== $expectedRollbackIds) {
        throw new RuntimeException('shared database migration rollback did not reverse the applied batch');
    }
    $afterRollback = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 't01\\_acceptance\\_%'"
        . ' ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($afterRollback !== [$prefix . 'geo_migrations']) {
        throw new RuntimeException('shared database rollback left tenant-independent migration tables behind');
    }

    $secondMigrationIds = $manager->migrate();
    if ($secondMigrationIds !== $firstMigrationIds) {
        throw new RuntimeException('shared database re-migration did not reapply the same migration set');
    }
    $secondCreated = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES'
        . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 't01\\_acceptance\\_%'"
        . ' ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($secondCreated !== $firstCreated) {
        throw new RuntimeException('shared database re-migration changed the isolated table set');
    }
} catch (Throwable $exception) {
    $failure = 'shared database migration failed class=' . str_replace('\\', '.', get_class($exception))
        . ' code=' . (string) $exception->getCode();
} finally {
    if ($pdo instanceof PDO) {
        $cleanupTables = $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES'
            . " WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 't01\\_acceptance\\_%'"
            . ' ORDER BY TABLE_NAME DESC'
        )->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($cleanupTables as $table) {
                if (preg_match('/^t01_acceptance_[A-Za-z0-9_]+$/', (string) $table) !== 1) {
                    throw new RuntimeException('shared database cleanup rejected an unexpected table name');
                }
                $pdo->exec('DROP TABLE `' . $table . '`');
            }
        } catch (Throwable $cleanupException) {
            $failure = 'shared database cleanup failed class='
                . str_replace('\\', '.', get_class($cleanupException))
                . ' code=' . (string) $cleanupException->getCode();
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        $after = $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME'
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($before !== [] && $after !== $before) {
            $failure = 'shared database table list changed after cleanup';
        }
    }
}

if ($failure !== null) {
    fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

fwrite(STDOUT, "PASS: shared database migration namespace up/down/up with exact cleanup\n");

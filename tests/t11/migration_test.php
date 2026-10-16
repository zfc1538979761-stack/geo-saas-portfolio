<?php

declare(strict_types=1);

define("ROOT_PATH", dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define("APP_PATH", ROOT_PATH . "application" . DIRECTORY_SEPARATOR);
require ROOT_PATH . "thinkphp" . DIRECTORY_SEPARATOR . "base.php";
\think\Loader::addNamespace("app", APP_PATH);

// This test MUST run with GEO_T11_MIGRATION_TEST=1 pointing at an isolated DB
$envPrefix = getenv("GEO_T11_MIGRATION_TEST");
if ($envPrefix !== "1" && $envPrefix !== "true") {
    echo "SKIP: Set GEO_T11_MIGRATION_TEST=1 for isolated migration test (requires separate MySQL credentials).\n";
    exit(0);
}

$dbHost = getenv("GEO_T11_DB_HOST") ?: "127.0.0.1";
$dbPort = getenv("GEO_T11_DB_PORT") ?: "33317";
$dbName = getenv("GEO_T11_DB_NAME") ?: "t11_acceptance";
$dbUser = getenv("GEO_T11_DB_USER") ?: "root";
$dbPass = getenv("GEO_T11_DB_PASS") ?: "";

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (\Throwable $e) {
    echo "SKIP: Cannot connect to isolated MySQL at {$dbHost}:{$dbPort} for T11 migration test.\n";
    exit(0);
}

// Verify this is a throwaway database
$nameCheck = $pdo->query("SELECT DATABASE()")->fetchColumn();
if (strpos($nameCheck, "t11_acceptance") === false && strpos($nameCheck, "_test") === false) {
    echo "FAIL: Refusing to run migration test on non-test database: {$nameCheck}\n";
    exit(1);
}

$prefix = "t11mig_";

// Run migration up
echo "Migrate UP...\n";
try {
    $migration = require ROOT_PATH . "database/migrations/202608060012_create_publish_orders.php";
    if (!$migration instanceof \app\common\migration\Migration) {
        throw new RuntimeException("T11 migration file did not return a Migration instance.");
    }
    $migration->up($pdo, $prefix);
    echo "PASS: Migration up.\n";
} catch (\Throwable $e) {
    echo "FAIL: Migration up: " . $e->getMessage() . "\n";
    exit(1);
}

// Verify tables
foreach (["publish_orders", "publish_order_items", "order_status_logs"] as $t) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute(["t" => $prefix . $t]);
    if ((int)$stmt->fetchColumn() !== 1) {
        echo "FAIL: Table {$prefix}{$t} not found after migration.\n";
        exit(1);
    }
}
echo "PASS: All 3 tables created.\n";

// Rollback
echo "Rollback...\n";
try {
    $migration->down($pdo, $prefix);
    echo "PASS: Migration down.\n";
} catch (\Throwable $e) {
    echo "FAIL: Migration down: " . $e->getMessage() . "\n";
    exit(1);
}

// Verify tables gone
foreach (["publish_orders", "publish_order_items", "order_status_logs"] as $t) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute(["t" => $prefix . $t]);
    if ((int)$stmt->fetchColumn() !== 0) {
        echo "FAIL: Table {$prefix}{$t} still exists after rollback.\n";
        exit(1);
    }
}
echo "PASS: All 3 tables removed.\n";

// Re-migrate
echo "Re-migrate...\n";
try {
    $migration->up($pdo, $prefix);
    echo "PASS: Re-migration successful.\n";
} catch (\Throwable $e) {
    echo "FAIL: Re-migration: " . $e->getMessage() . "\n";
    exit(1);
}

// Cleanup
$migration->down($pdo, $prefix);
echo "PASS: Cleanup after test.\n";
echo "T11 Migration: PASS\n";

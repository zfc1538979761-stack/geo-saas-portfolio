<?php

declare(strict_types=1);

define("ROOT_PATH", dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define("APP_PATH", ROOT_PATH . "application" . DIRECTORY_SEPARATOR);
require ROOT_PATH . "thinkphp" . DIRECTORY_SEPARATOR . "base.php";
\think\Loader::addNamespace("app", APP_PATH);

use app\common\billing\BillingService;
use app\common\tenant\PdoAuditLogger;

$config = []; $section = "global";
foreach (file(ROOT_PATH . ".env") as $line) {
    $line = trim($line);
    if ($line === "" || $line[0] === "#") continue;
    if (preg_match('/^\[(.+)\]$/', $line, $m)) { $section = $m[1]; continue; }
    $parts = explode("=", $line, 2);
    if (count($parts) === 2) { $k = trim($parts[0]); $v = trim($parts[1], " \t\"\r\n"); $config[$section][$k] = $v; }
}
$db = $config["database"] ?? [];
$db = [
    "hostname" => getenv("GEO_DATABASE_HOSTNAME") ?: ($db["hostname"] ?? ""),
    "hostport" => getenv("GEO_DATABASE_HOSTPORT") ?: ($db["hostport"] ?? "3306"),
    "database" => getenv("GEO_DATABASE_DATABASE") ?: ($db["database"] ?? "fastadmin"),
    "username" => getenv("GEO_DATABASE_USERNAME") ?: ($db["username"] ?? "root"),
    "password" => getenv("GEO_DATABASE_PASSWORD") ?: ($db["password"] ?? ""),
    "prefix" => getenv("GEO_DATABASE_PREFIX") ?: ($db["prefix"] ?? "fa_"),
];
$prefix = $db["prefix"] ?? "fa_";
$dsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", $db["hostname"] ?? "127.0.0.1", $db["hostport"] ?? "3306", $db["database"] ?? "fastadmin");
$pdo = new PDO($dsn, $db["username"] ?? "root", $db["password"] ?? "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$pass = 0; $fail = 0;
function test(string $n, callable $f): void {
    global $pass, $fail;
    try { $f(); echo "PASS: {$n}\n"; $pass++; }
    catch (\Throwable $e) { echo "FAIL: {$n} - " . $e->getMessage() . "\n"; $fail++; }
}
function assertTrue(bool $c, string $m = ""): void { if (!$c) throw new \RuntimeException($m ?: "assertion failed"); }

echo "T10 Migration Test\n==================\n";

$wTbl = $prefix . "wallets";
$lTbl = $prefix . "ledger_entries";

test("wallets table exists", function () use ($pdo, $wTbl) {
    assertTrue($pdo->query("SHOW TABLES LIKE '{$wTbl}'")->rowCount() === 1);
});

test("ledger_entries table exists", function () use ($pdo, $lTbl) {
    assertTrue($pdo->query("SHOW TABLES LIKE '{$lTbl}'")->rowCount() === 1);
});

test("wallets has DECIMAL(18,2)", function () use ($pdo, $wTbl) {
    foreach ($pdo->query("DESCRIBE {$wTbl}")->fetchAll() as $r) {
        if ($r["Field"] === "available_balance") assertTrue(stripos($r["Type"], "decimal(18,2)") !== false);
    }
});

test("ledger has idempotency unique", function () use ($pdo, $lTbl) {
    assertTrue($pdo->query("SHOW INDEX FROM {$lTbl} WHERE Key_name='uq_ledger_idempotency'")->rowCount() > 0);
});

test("new company wallet creation via BillingService", function () use ($pdo, $prefix, $wTbl) {
    $audit = new PdoAuditLogger($pdo, $prefix);
    $svc = new BillingService($pdo, $audit, $prefix);
    $cid = 0;
    try {
        $stmt = $pdo->prepare("INSERT INTO {$prefix}companies (name,status,createtime,updatetime) VALUES (:n,:st,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
        $stmt->execute(["n" => "T10_MIG_" . uniqid(), "st" => "normal"]);
        $cid = (int) $pdo->lastInsertId();
        $wallet = $svc->ensureWallet($cid);
        assertTrue(($wallet["available_balance"] ?? "0.00") === "0.00", "New wallet available_balance must be 0.00");
        assertTrue(($wallet["frozen_balance"] ?? "0.00") === "0.00", "New wallet frozen_balance must be 0.00");
        $count = $pdo->query("SELECT COUNT(*) FROM {$wTbl} WHERE company_id={$cid}")->fetchColumn();
        assertTrue((int) $count === 1, "New company must have exactly one wallet");
    } finally {
        if ($cid > 0) {
            $pdo->exec("DELETE FROM {$prefix}ledger_entries WHERE company_id={$cid}");
            $pdo->exec("DELETE FROM {$prefix}wallets WHERE company_id={$cid}");
            $pdo->exec("DELETE FROM {$prefix}companies WHERE id={$cid}");
        }
    }
});

echo "\n---\nT10 Migration: {$pass} PASS, {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);

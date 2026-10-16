<?php

declare(strict_types=1);

define("ROOT_PATH", dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define("APP_PATH", ROOT_PATH . "application" . DIRECTORY_SEPARATOR);
require ROOT_PATH . "thinkphp" . DIRECTORY_SEPARATOR . "base.php";
\think\Loader::addNamespace("app", APP_PATH);

use app\common\billing\BillingException;
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
$db = $config["database"] ?? $config["global"] ?? [];
$db = [
    "hostname" => getenv("GEO_DATABASE_HOSTNAME") ?: ($db["hostname"] ?? ""),
    "hostport" => getenv("GEO_DATABASE_HOSTPORT") ?: ($db["hostport"] ?? "3306"),
    "database" => getenv("GEO_DATABASE_DATABASE") ?: ($db["database"] ?? "fastadmin"),
    "username" => getenv("GEO_DATABASE_USERNAME") ?: ($db["username"] ?? "root"),
    "password" => getenv("GEO_DATABASE_PASSWORD") ?: ($db["password"] ?? ""),
    "prefix" => getenv("GEO_DATABASE_PREFIX") ?: ($db["prefix"] ?? "fa_"),
];
$prefix = $db["prefix"] ?? "fa_";
$dsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
    $db["hostname"] ?? "127.0.0.1", $db["hostport"] ?? "3306", $db["database"] ?? "fastadmin");
$pdo = new PDO($dsn, $db["username"] ?? "root", $db["password"] ?? "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pass = 0; $fail = 0;
function test(string $n, callable $f): void {
    global $pass, $fail;
    try { $f(); echo "PASS: {$n}\n"; $pass++; }
    catch (\Throwable $e) { echo "FAIL: {$n} - " . $e->getMessage() . "\n"; $fail++; }
}
function assertTrue(bool $c, string $m = ""): void { if (!$c) throw new \RuntimeException($m ?: "assertion failed"); }

echo "T10 Billing Service Tests\n=========================\n";

$auditLogger = new PdoAuditLogger($pdo, $prefix);
$svc = new BillingService($pdo, $auditLogger, $prefix);

// Helper: create a fresh company + wallet for a test, clean up after
function withCompany(PDO $pdo, string $prefix, callable $fn): void {
    $stmt = $pdo->prepare("INSERT INTO {$prefix}companies (name,status,createtime,updatetime) VALUES (?,?,NOW(),NOW())");
    $stmt->execute(["T10_" . uniqid(), "normal"]);
    $cid = (int)$pdo->lastInsertId();
    try { $fn($cid); }
    finally {
        $pdo->exec("DELETE FROM {$prefix}ledger_entries WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}wallets WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}companies WHERE id={$cid}");
    }
}

test("001 wallet zero", function () use ($svc, $pdo, $prefix) {
    withCompany($pdo, $prefix, function (int $cid) use ($svc) {
        $w = $svc->ensureWallet($cid);
        assertTrue($w["available_balance"] === "0.00");
        assertTrue($w["frozen_balance"] === "0.00");
    });
});

test("002 idempotent wallet", function () use ($svc, $pdo, $prefix) {
    withCompany($pdo, $prefix, function (int $cid) use ($svc) {
        $w1 = $svc->ensureWallet($cid);
        $w2 = $svc->ensureWallet($cid);
        assertTrue($w1["id"] === $w2["id"]);
    });
});

test("003 manual credit", function () use ($svc, $pdo, $prefix) {
    withCompany($pdo, $prefix, function (int $cid) use ($svc) {
        $svc->ensureWallet($cid);
        $svc->manualCredit($cid, "100.00", 99, "test", "t10_003_" . uniqid());
        assertTrue(($svc->getWallet($cid))["available_balance"] === "100.00");
    });
});

test("004 manual debit", function () use ($svc, $pdo, $prefix) {
    withCompany($pdo, $prefix, function (int $cid) use ($svc) {
        $svc->ensureWallet($cid);
        $svc->manualCredit($cid, "100.00", 99, "setup", "t10_s4_" . uniqid());
        $svc->manualDebit($cid, "30.00", 99, "test", "t10_004_" . uniqid());
        assertTrue(($svc->getWallet($cid))["available_balance"] === "70.00");
    });
});

test("005 insufficient fails", function () use ($svc, $pdo, $prefix) {
    withCompany($pdo, $prefix, function (int $cid) use ($svc) {
        $svc->ensureWallet($cid);
        $svc->manualCredit($cid, "50.00", 99, "setup", "t10_s5_" . uniqid());
        try { $svc->manualDebit($cid, "100.00", 99, "fail", "t10_005_" . uniqid()); throw new \RuntimeException("fail"); }
        catch (BillingException $e) { assertTrue($e->getCode() === 1420); }
    });
});

test("006 freeze", function () use ($svc, $pdo, $prefix) {
    withCompany($pdo, $prefix, function (int $cid) use ($svc) {
        $svc->ensureWallet($cid);
        $svc->manualCredit($cid, "200.00", 99, "setup", "t10_s6_" . uniqid());
        $svc->freeze($cid, "order", "ord-6", "80.00", "t10_006_" . uniqid());
        $w = $svc->getWallet($cid);
        assertTrue($w["available_balance"] === "120.00");
        assertTrue($w["frozen_balance"] === "80.00");
    });
});

test("007 freeze insufficient", function () use ($svc, $pdo, $prefix) {
    withCompany($pdo, $prefix, function (int $cid) use ($svc) {
        $svc->ensureWallet($cid);
        $svc->manualCredit($cid, "10.00", 99, "setup", "t10_s7_" . uniqid());
        try { $svc->freeze($cid, "order", "ord-7", "50.00", "t10_007_" . uniqid()); throw new \RuntimeException("fail"); }
        catch (BillingException $e) { assertTrue($e->getCode() === 1420); }
    });
});

echo "\n---\nT10 Billing: {$pass} PASS, {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);

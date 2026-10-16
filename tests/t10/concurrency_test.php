<?php

declare(strict_types=1);

define("ROOT_PATH", dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define("APP_PATH", ROOT_PATH . "application" . DIRECTORY_SEPARATOR);
require ROOT_PATH . "thinkphp" . DIRECTORY_SEPARATOR . "base.php";
\think\Loader::addNamespace("app", APP_PATH);

use app\common\billing\BillingException;
use app\common\billing\BillingService;
use app\common\tenant\PdoAuditLogger;

$cfg = []; $sec = "global";
foreach (file(ROOT_PATH . ".env") as $line) {
    $line = trim($line);
    if ($line === "" || $line[0] === "#") continue;
    if (preg_match('/^\[(.+)\]$/', $line, $m)) { $sec = $m[1]; continue; }
    $parts = explode("=", $line, 2);
    if (count($parts) === 2) { $k = trim($parts[0]); $v = trim($parts[1], " \t\"\r\n"); $cfg[$sec][$k] = $v; }
}
$db = $cfg["database"] ?? [];
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

$auditLogger = new PdoAuditLogger($pdo, $prefix);
$svc = new BillingService($pdo, $auditLogger, $prefix);

function withCompany(PDO $pdo, string $prefix, callable $fn): void {
    $stmt = $pdo->prepare("INSERT INTO {$prefix}companies (name,status,createtime,updatetime) VALUES (?,?,NOW(),NOW())");
    $stmt->execute(["T10CONC_" . uniqid(), "normal"]);
    $cid = (int)$pdo->lastInsertId();
    try { $fn($cid); }
    finally {
        $pdo->exec("DELETE FROM {$prefix}ledger_entries WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}wallets WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}companies WHERE id={$cid}");
    }
}

echo "T10 Concurrency Test\n====================\n";

test("Scenario A: balance competition", function () use ($svc, $pdo, $prefix) {
    withCompany($pdo, $prefix, function (int $cid) use ($svc) {
        $svc->ensureWallet($cid);
        $svc->manualCredit($cid, "100.00", 99, "setup", "cca_" . uniqid());

        $suf = uniqid();
        $rA = null; $rB = null;
        try { $rA = $svc->freeze($cid, "order", "ordA-" . $suf, "80.00", "ccakA_" . uniqid()); } catch (BillingException $e) {}
        try { $rB = $svc->freeze($cid, "order", "ordB-" . $suf, "80.00", "ccakB_" . uniqid()); } catch (BillingException $e) {}

        $sc = ($rA ? 1 : 0) + ($rB ? 1 : 0);
        assertTrue($sc === 1, "Expected 1 success, got {$sc}");
        $w = $svc->getWallet($cid);
        assertTrue($w["available_balance"] === "20.00", "Expected 20 avail, got {$w['available_balance']}");
        assertTrue($w["frozen_balance"] === "80.00", "Expected 80 frozen, got {$w['frozen_balance']}");
    });
});

test("Scenario B: same key idempotent", function () use ($svc, $pdo, $prefix) {
    withCompany($pdo, $prefix, function (int $cid) use ($svc) {
        $svc->ensureWallet($cid);
        $svc->manualCredit($cid, "200.00", 99, "setup", "ccb_" . uniqid());

        $key = "ccb_idem_" . uniqid();
        $bid = "ord-idem-" . uniqid();
        $r1 = $svc->freeze($cid, "order", $bid, "50.00", $key);
        $r2 = $svc->freeze($cid, "order", $bid, "50.00", $key);
        assertTrue($r1["id"] === $r2["id"], "Expected same entry ID, got {$r1['id']} vs {$r2['id']}");
    });
});

test("Scenario C: key conflict diff params", function () use ($svc, $pdo, $prefix) {
    withCompany($pdo, $prefix, function (int $cid) use ($svc) {
        $svc->ensureWallet($cid);
        $svc->manualCredit($cid, "200.00", 99, "setup", "ccc_" . uniqid());

        $key = "ccc_conflict_" . uniqid();
        $bid = "ord-conflict-" . uniqid();
        $svc->freeze($cid, "order", $bid, "30.00", $key);
        $conflict = false;
        try { $svc->freeze($cid, "order", $bid, "50.00", $key); }
        catch (BillingException $e) { $conflict = ($e->getCode() === 1426); }
        assertTrue($conflict, "Expected idempotency conflict code 1426");
    });
});

echo "\n---\nT10 Concurrency: {$pass} PASS, {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);

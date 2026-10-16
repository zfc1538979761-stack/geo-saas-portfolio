<?php

declare(strict_types=1);

define("ROOT_PATH", dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define("APP_PATH", ROOT_PATH . "application" . DIRECTORY_SEPARATOR);
require ROOT_PATH . "thinkphp" . DIRECTORY_SEPARATOR . "base.php";
\think\Loader::addNamespace("app", APP_PATH);

use app\common\billing\BillingService;
use app\common\publishing\PublishOrderService;
use app\common\publishing\PublishException;
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
    try { $f(); echo "  PASS: {$n}\n"; $pass++; }
    catch (\Throwable $e) { echo "  FAIL: {$n} - " . $e->getMessage() . "\n"; $fail++; }
}
function assertTrue(bool $c, string $m = ""): void { if (!$c) throw new \RuntimeException($m ?: "assertion failed"); }
function assertEq(string $a, string $b, string $m = ""): void { if (bccomp($a, $b, 2) !== 0) throw new \RuntimeException($m ?: "{$a} != {$b}"); }

echo "T11 Final Verification\n=====================\n";

$auditLogger = new PdoAuditLogger($pdo, $prefix);
$billing = new BillingService($pdo, $auditLogger, $prefix);
$svc = new PublishOrderService($pdo, $billing, $prefix);

function makeCompany(PDO $pdo, string $prefix): int {
    $stmt = $pdo->prepare("INSERT INTO {$prefix}companies (name,status,createtime,updatetime) VALUES (:n,:st,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
    $stmt->execute(["n" => "T11FV_" . uniqid(), "st" => "normal"]);
    $cid = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT IGNORE INTO {$prefix}wallets (company_id,currency,available_balance,frozen_balance,version) VALUES ({$cid},'CNY',0.00,0.00,0)");
    return $cid;
}

function makeContent(PDO $pdo, string $prefix, int $cid): array {
    $stmt = $pdo->prepare("INSERT INTO {$prefix}keywords (company_id,keyword,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kw,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
    $stmt->execute(["cid" => $cid, "kw" => "T11FV_KW", "st" => "normal"]); $kwid = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO {$prefix}questions (company_id,keyword_id,text,text_hash,source,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kwid,:t,:th,:src,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
    $stmt->execute(["cid" => $cid, "kwid" => $kwid, "t" => "T11FV_Q", "th" => hash("sha256", "T11FV_Q", true), "src" => "manual", "st" => "normal"]); $qid = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO {$prefix}prompt_templates (company_id,logical_key,version_no,name,scene,content,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:lk,:vn,:nm,:sc,:ct,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
    $stmt->execute(["cid" => $cid, "lk" => "t11fv", "vn" => 1, "nm" => "T11FV_PT", "sc" => "article", "ct" => "p", "st" => "normal"]); $ptid = (int)$pdo->lastInsertId();
    $now = time();
    $stmt = $pdo->prepare("INSERT INTO {$prefix}articles (company_id,keyword_id,question_id,title,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kwid,:qid,:t,:st,:cb,:ub,:ct,:ut)");
    $stmt->execute(["cid" => $cid, "kwid" => $kwid, "qid" => $qid, "t" => "T11FV Article", "st" => "draft", "cb" => 0, "ub" => 0, "ct" => $now, "ut" => $now]); $aid = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO {$prefix}article_versions (company_id,article_id,version_no,title_snapshot,content,source,prompt_template_id,created_by,createtime) VALUES (:cid,:aid,:vn,:ts,:ct,:src,:ptid,:cb,UNIX_TIMESTAMP())");
    $stmt->execute(["cid" => $cid, "aid" => $aid, "vn" => 1, "ts" => "T11FV Article", "ct" => "T11FV Content", "src" => "manual", "ptid" => $ptid, "cb" => 0]); $avid = (int)$pdo->lastInsertId();
    $pdo->exec("UPDATE {$prefix}articles SET current_version_id={$avid} WHERE id={$aid}");
    $sid = (int)($pdo->query("SELECT id FROM {$prefix}suppliers WHERE code='mock' LIMIT 1")->fetchColumn() ?: 0);
    if ($sid === 0) { $pdo->exec("INSERT INTO {$prefix}suppliers (code,display_name,status,created_at,updated_at) VALUES ('mock','Mock','normal',NOW(),NOW())"); $sid = (int)$pdo->lastInsertId(); }
    $chid = (int)($pdo->query("SELECT id FROM {$prefix}channels WHERE code='official_media' LIMIT 1")->fetchColumn() ?: 0);
    if ($chid === 0) { $pdo->exec("INSERT INTO {$prefix}channels (code,name,category,status,supplier_confirmed,created_at,updated_at) VALUES ('official_media','OM','official_media','normal',0,NOW(),NOW())"); $chid = (int)$pdo->lastInsertId(); }
    return [$aid, $avid, $sid, $chid];
}

function makeMedia(PDO $pdo, string $prefix, int $sid, int $chid, string $cost): int {
    $stmt = $pdo->prepare("INSERT INTO {$prefix}media_resources (supplier_id,external_id,channel_id,name,cost_price,cost_currency,status,attributes_json,last_synced_at,created_at,updated_at) VALUES (:sid,:eid,:chid,:nm,:cost,'CNY','active','{}',NOW(),NOW(),NOW())");
    $stmt->execute(["sid" => $sid, "eid" => "ext_" . uniqid(), "chid" => $chid, "nm" => "T11FV_M", "cost" => $cost]);
    return (int)$pdo->lastInsertId();
}

function cleanAll(PDO $pdo, string $prefix, int $cid): void {
    $pdo->exec("DELETE FROM {$prefix}order_status_logs WHERE company_id={$cid}");
    $pdo->exec("DELETE FROM {$prefix}publish_order_items WHERE company_id={$cid}");
    $pdo->exec("DELETE FROM {$prefix}publish_orders WHERE company_id={$cid}");
    $pdo->exec("DELETE FROM {$prefix}ledger_entries WHERE company_id={$cid}");
    $pdo->exec("DELETE FROM {$prefix}wallets WHERE company_id={$cid}");
    $pdo->exec("UPDATE {$prefix}articles SET current_version_id=NULL WHERE company_id={$cid}");
    $pdo->exec("DELETE FROM {$prefix}article_versions WHERE company_id={$cid}");
    $pdo->exec("DELETE FROM {$prefix}articles WHERE company_id={$cid}");
    $pdo->exec("DELETE FROM {$prefix}prompt_templates WHERE company_id={$cid}");
    $pdo->exec("DELETE FROM {$prefix}questions WHERE company_id={$cid}");
    $pdo->exec("DELETE FROM {$prefix}keywords WHERE company_id={$cid}");
    $pdo->exec("DELETE FROM {$prefix}companies WHERE id={$cid}");
}

echo "\n--- Cost Isolation ---\n";
test("1.01 order no cost columns", function() use ($svc, $pdo, $prefix, $billing) {
    $c = makeCompany($pdo, $prefix); list($a, $av, $s, $ch) = makeContent($pdo, $prefix, $c);
    $m = makeMedia($pdo, $prefix, $s, $ch, "10.00");
    $billing->ensureWallet($c); $billing->manualCredit($c, "100.00", 99, "t", "fv1a_" . uniqid());
    $p = $svc->preview($c, $a, $av, [$m]);
    $o = $svc->create($c, 1, $a, $av, [$m], "fv1a_" . uniqid(), $p["price_fingerprint"]);
    $forbidden = ["cost_price","cost_snapshot","supplier_cost","raw_payload","supplier_raw_status"];
    foreach ($forbidden as $f) assertTrue(!isset($o[$f]), "Order has {$f}");
    $d = $svc->getDetail($c, $o["id"]);
    foreach ($d["items"] as $item) foreach ($forbidden as $f) assertTrue(!isset($item[$f]));
    cleanAll($pdo, $prefix, $c);
});

test("1.02 item only allowed fields", function() use ($svc, $pdo, $prefix, $billing) {
    $c = makeCompany($pdo, $prefix); list($a, $av, $s, $ch) = makeContent($pdo, $prefix, $c);
    $m = makeMedia($pdo, $prefix, $s, $ch, "10.00");
    $billing->ensureWallet($c); $billing->manualCredit($c, "100.00", 99, "t", "fv1b_" . uniqid());
    $p = $svc->preview($c, $a, $av, [$m]);
    $o = $svc->create($c, 1, $a, $av, [$m], "fv1b_" . uniqid(), $p["price_fingerprint"]);
    $d = $svc->getDetail($c, $o["id"]);
    $allowed = ["id","media_id","media_name","media_display_name","sale_snapshot","status","supplier_order_no","published_url","created_at","updated_at"];
    foreach ($d["items"] as $item) foreach (array_keys($item) as $k) assertTrue(in_array($k, $allowed, true), "Field {$k}");
    cleanAll($pdo, $prefix, $c);
});

echo "\n--- Tenant ---\n";
test("2.01 B cant list A", function() use ($svc, $pdo, $prefix, $billing) {
    $cA = makeCompany($pdo, $prefix); list($aA, $avA, $s, $ch) = makeContent($pdo, $prefix, $cA);
    $m = makeMedia($pdo, $prefix, $s, $ch, "10.00");
    $billing->ensureWallet($cA); $billing->manualCredit($cA, "100.00", 99, "t", "fv2a_" . uniqid());
    $p = $svc->preview($cA, $aA, $avA, [$m]);
    $svc->create($cA, 1, $aA, $avA, [$m], "fv2a_" . uniqid(), $p["price_fingerprint"]);
    $cB = makeCompany($pdo, $prefix);
    assertTrue(count($svc->listOrders($cB, 1, 20)["items"]) === 0);
    cleanAll($pdo, $prefix, $cA); cleanAll($pdo, $prefix, $cB);
});

test("2.02 B cant get A detail", function() use ($svc, $pdo, $prefix, $billing) {
    $cA = makeCompany($pdo, $prefix); list($aA, $avA, $s, $ch) = makeContent($pdo, $prefix, $cA);
    $m = makeMedia($pdo, $prefix, $s, $ch, "10.00");
    $billing->ensureWallet($cA); $billing->manualCredit($cA, "100.00", 99, "t", "fv2b_" . uniqid());
    $p = $svc->preview($cA, $aA, $avA, [$m]);
    $oA = $svc->create($cA, 1, $aA, $avA, [$m], "fv2b_" . uniqid(), $p["price_fingerprint"]);
    $cB = makeCompany($pdo, $prefix);
    try { $svc->getDetail($cB, $oA["id"]); assertTrue(false); } catch (PublishException $e) { }
    cleanAll($pdo, $prefix, $cA); cleanAll($pdo, $prefix, $cB);
});

test("2.03 B cant cancel A", function() use ($svc, $pdo, $prefix, $billing) {
    $cA = makeCompany($pdo, $prefix); list($aA, $avA, $s, $ch) = makeContent($pdo, $prefix, $cA);
    $m = makeMedia($pdo, $prefix, $s, $ch, "10.00");
    $billing->ensureWallet($cA); $billing->manualCredit($cA, "100.00", 99, "t", "fv2c_" . uniqid());
    $p = $svc->preview($cA, $aA, $avA, [$m]);
    $oA = $svc->create($cA, 1, $aA, $avA, [$m], "fv2c_" . uniqid(), $p["price_fingerprint"]);
    $cB = makeCompany($pdo, $prefix);
    try { $svc->cancel($cB, $oA["id"], 1); assertTrue(false); } catch (PublishException $e) { }
    cleanAll($pdo, $prefix, $cA); cleanAll($pdo, $prefix, $cB);
});

echo "\n--- Reconciliation ---\n";
test("3.01 total = items sum", function() use ($svc, $pdo, $prefix, $billing) {
    $c = makeCompany($pdo, $prefix); list($a, $av, $s, $ch) = makeContent($pdo, $prefix, $c);
    $m1 = makeMedia($pdo, $prefix, $s, $ch, "10.00"); $m2 = makeMedia($pdo, $prefix, $s, $ch, "15.00");
    $billing->ensureWallet($c); $billing->manualCredit($c, "100.00", 99, "t", "fv3a_" . uniqid());
    $p = $svc->preview($c, $a, $av, [$m1, $m2]);
    $o = $svc->create($c, 1, $a, $av, [$m1, $m2], "fv3a_" . uniqid(), $p["price_fingerprint"]);
    $d = $svc->getDetail($c, $o["id"]);
    $sum = "0.00"; foreach ($d["items"] as $it) $sum = bcadd($sum, $it["sale_snapshot"], 2);
    assertEq($o["total_amount"], $sum);
    cleanAll($pdo, $prefix, $c);
});

test("3.02 frozen = wallet", function() use ($svc, $pdo, $prefix, $billing) {
    $c = makeCompany($pdo, $prefix); list($a, $av, $s, $ch) = makeContent($pdo, $prefix, $c);
    $m = makeMedia($pdo, $prefix, $s, $ch, "10.00");
    $billing->ensureWallet($c); $billing->manualCredit($c, "100.00", 99, "t", "fv3b_" . uniqid());
    $p = $svc->preview($c, $a, $av, [$m]);
    $o = $svc->create($c, 1, $a, $av, [$m], "fv3b_" . uniqid(), $p["price_fingerprint"]);
    assertEq($o["frozen_amount"], $billing->getWallet($c)["frozen_balance"]);
    cleanAll($pdo, $prefix, $c);
});

test("3.03 wallet = ledger", function() use ($billing, $pdo, $prefix) {
    $c = makeCompany($pdo, $prefix); $billing->ensureWallet($c);
    $billing->manualCredit($c, "50.00", 99, "t", "fv3c_a" . uniqid());
    $billing->manualCredit($c, "30.00", 99, "t", "fv3c_b" . uniqid());
    $billing->manualDebit($c, "20.00", 99, "t", "fv3c_c" . uniqid());
    $w = $billing->getWallet($c);
    $ledger = $billing->listLedger($c, 1, 100);
    $sum = "0.00"; foreach ($ledger["items"] as $e) $sum = bcadd($sum, $e["available_delta"], 2);
    assertEq($sum, $w["available_balance"]);
    cleanAll($pdo, $prefix, $c);
});

test("3.04 unique biz key once", function() use ($billing, $pdo, $prefix) {
    $c = makeCompany($pdo, $prefix); $billing->ensureWallet($c); $billing->manualCredit($c, "100.00", 99, "t", "fv3d_" . uniqid());
    $billing->freeze($c, "test", "b1", "30.00", "fv3d_frz", "");
    $billing->freeze($c, "test", "b1", "30.00", "fv3d_frz", "");
    assertTrue(count($billing->listLedger($c, 1, 100, "freeze")["items"]) === 1);
    cleanAll($pdo, $prefix, $c);
});

test("3.05 cancel zero frozen", function() use ($svc, $pdo, $prefix, $billing) {
    $c = makeCompany($pdo, $prefix); list($a, $av, $s, $ch) = makeContent($pdo, $prefix, $c);
    $m = makeMedia($pdo, $prefix, $s, $ch, "10.00");
    $billing->ensureWallet($c); $billing->manualCredit($c, "100.00", 99, "t", "fv3e_" . uniqid());
    $p = $svc->preview($c, $a, $av, [$m]);
    $o = $svc->create($c, 1, $a, $av, [$m], "fv3e_" . uniqid(), $p["price_fingerprint"]);
    $svc->cancel($c, $o["id"], 1);
    assertEq($billing->getWallet($c)["frozen_balance"], "0.00");
    cleanAll($pdo, $prefix, $c);
});

echo "\n--- Rollback ---\n";
test("4.01 no balance rolls back", function() use ($svc, $pdo, $prefix) {
    $c = makeCompany($pdo, $prefix); list($a, $av, $s, $ch) = makeContent($pdo, $prefix, $c);
    $m = makeMedia($pdo, $prefix, $s, $ch, "10.00");
    $p = $svc->preview($c, $a, $av, [$m]);
    $beforeW = (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}wallets WHERE company_id={$c}")->fetchColumn();
    try { $svc->create($c, 1, $a, $av, [$m], "fv4a_" . uniqid(), $p["price_fingerprint"]); } catch (\Throwable $e) {}
    // Verify: no ledger entries (freeze was never attempted), wallet unchanged
    assertTrue((int)$pdo->query("SELECT COUNT(*) FROM {$prefix}ledger_entries WHERE company_id={$c}")->fetchColumn() === 0, "No ledger residue");
    $w = $pdo->query("SELECT * FROM {$prefix}wallets WHERE company_id={$c}")->fetch();
    assertTrue($w && bccomp($w["available_balance"], "0.00", 2) === 0 && bccomp($w["frozen_balance"], "0.00", 2) === 0, "Wallet unchanged");
    cleanAll($pdo, $prefix, $c);
});

test("4.02 retry succeeds", function() use ($svc, $pdo, $prefix, $billing) {
    $c = makeCompany($pdo, $prefix); list($a, $av, $s, $ch) = makeContent($pdo, $prefix, $c);
    $m = makeMedia($pdo, $prefix, $s, $ch, "10.00");
    $p = $svc->preview($c, $a, $av, [$m]);
    try { $svc->create($c, 1, $a, $av, [$m], "fv4b_" . uniqid(), $p["price_fingerprint"]); } catch (\Throwable $e) {}
    $billing->ensureWallet($c); $billing->manualCredit($c, "100.00", 99, "t", "fv4bcr_" . uniqid());
    $o = $svc->create($c, 1, $a, $av, [$m], "fv4b_r2_" . uniqid(), $p["price_fingerprint"]);
    assertTrue($o["status"] === "funds_frozen");
    cleanAll($pdo, $prefix, $c);
});

echo "\n--- No External HTTP ---\n";
test("5.01 no Guzzle in service", function() {
    $c = file_get_contents(APP_PATH . "common/publishing/PublishOrderService.php");
    assertTrue(strpos($c, "Guzzle") === false);
    assertTrue(strpos($c, "MediaMarketApiClient") === false);
});
test("5.02 no MediaMarket in API", function() {
    $c = file_get_contents(APP_PATH . "api/controller/Order.php");
    assertTrue(strpos($c, "MediaMarket") === false);
});

echo "\n====================\n";
echo "T11 Final: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);

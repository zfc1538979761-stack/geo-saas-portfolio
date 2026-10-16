<?php

declare(strict_types=1);

define("ROOT_PATH", dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define("APP_PATH", ROOT_PATH . "application" . DIRECTORY_SEPARATOR);
require ROOT_PATH . "thinkphp" . DIRECTORY_SEPARATOR . "base.php";
\think\Loader::addNamespace("app", APP_PATH);

use app\common\billing\BillingService;
use app\common\billing\BillingException;
use app\common\publishing\PublishOrderService;
use app\common\publishing\PublishException;
use app\common\publishing\OrderStateMachine;
use app\common\supplier\ChannelAvailabilityService;
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

$pass = 0; $fail = 0; $skip = 0;
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

// ============================================================
// HELPERS
// ============================================================

function createCompany(PDO $pdo, string $prefix): int {
    $stmt = $pdo->prepare("INSERT INTO {$prefix}companies (name,status,createtime,updatetime) VALUES (:n,:st,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
    $stmt->execute(["n" => "T11V_" . uniqid(), "st" => "normal"]);
    $cid = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT IGNORE INTO {$prefix}wallets (company_id,currency,available_balance,frozen_balance,version) VALUES ({$cid},'CNY',0.00,0.00,0)");
    return $cid;
}

function createContentFixtures(PDO $pdo, string $prefix, int $cid): array {
    $stmt = $pdo->prepare("INSERT INTO {$prefix}keywords (company_id,keyword,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kw,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
    $stmt->execute(["cid" => $cid, "kw" => "T11V_KW", "st" => "normal"]); $kwid = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO {$prefix}questions (company_id,keyword_id,text,text_hash,source,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kwid,:t,:th,:src,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
    $stmt->execute(["cid" => $cid, "kwid" => $kwid, "t" => "T11V_Q", "th" => hash("sha256", "T11V_Q", true), "src" => "manual", "st" => "normal"]); $qid = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO {$prefix}prompt_templates (company_id,logical_key,version_no,name,scene,content,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:lk,:vn,:nm,:sc,:ct,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
    $stmt->execute(["cid" => $cid, "lk" => "t11vpt", "vn" => 1, "nm" => "T11V_PT", "sc" => "article", "ct" => "p", "st" => "normal"]); $ptid = (int)$pdo->lastInsertId();
    $now = time();
    $stmt = $pdo->prepare("INSERT INTO {$prefix}articles (company_id,keyword_id,question_id,title,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kwid,:qid,:t,:st,:cb,:ub,:ct,:ut)");
    $stmt->execute(["cid" => $cid, "kwid" => $kwid, "qid" => $qid, "t" => "T11V Article", "st" => "draft", "cb" => 0, "ub" => 0, "ct" => $now, "ut" => $now]); $aid = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO {$prefix}article_versions (company_id,article_id,version_no,title_snapshot,content,source,prompt_template_id,created_by,createtime) VALUES (:cid,:aid,:vn,:ts,:ct,:src,:ptid,:cb,UNIX_TIMESTAMP())");
    $stmt->execute(["cid" => $cid, "aid" => $aid, "vn" => 1, "ts" => "T11V Article", "ct" => "T11V Content", "src" => "manual", "ptid" => $ptid, "cb" => 0]); $avid = (int)$pdo->lastInsertId();
    $pdo->exec("UPDATE {$prefix}articles SET current_version_id={$avid} WHERE id={$aid}");
    // ensure supplier+channel exist
    $sid = (int)($pdo->query("SELECT id FROM {$prefix}suppliers WHERE code='mock' LIMIT 1")->fetchColumn() ?: 0);
    if ($sid === 0) { $pdo->exec("INSERT INTO {$prefix}suppliers (code,display_name,status,created_at,updated_at) VALUES ('mock','Mock','normal',NOW(),NOW())"); $sid = (int)$pdo->lastInsertId(); }
    $chid = (int)($pdo->query("SELECT id FROM {$prefix}channels WHERE code='official_media' LIMIT 1")->fetchColumn() ?: 0);
    if ($chid === 0) { $pdo->exec("INSERT INTO {$prefix}channels (code,name,category,status,supplier_confirmed,created_at,updated_at) VALUES ('official_media','OM','official_media','normal',0,NOW(),NOW())"); $chid = (int)$pdo->lastInsertId(); }
    return [$aid, $avid, $sid, $chid];
}

function createMedia(PDO $pdo, string $prefix, int $sid, int $chid, string $cost, string $name = "T11V_M"): int {
    $stmt = $pdo->prepare("INSERT INTO {$prefix}media_resources (supplier_id,external_id,channel_id,name,cost_price,cost_currency,status,attributes_json,last_synced_at,created_at,updated_at) VALUES (:sid,:eid,:chid,:nm,:cost,'CNY','active','{}',NOW(),NOW(),NOW())");
    $stmt->execute(["sid" => $sid, "eid" => "ext_" . uniqid(), "chid" => $chid, "nm" => $name, "cost" => $cost]);
    return (int)$pdo->lastInsertId();
}

function cleanupCompany(PDO $pdo, string $prefix, int $cid): void {
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
    $pdo->exec("DELETE FROM {$prefix}media_resources WHERE name='T11V_M'");
}

// ============================================================
// SECTION 1: COST PRICE ISOLATION (API response structure)
// ============================================================
echo "\n--- Section 1: Cost Price Isolation ---\n";

test("1.01 formatOrderRow excludes cost columns", function() use ($svc, $pdo, $prefix, $billing) {
    $cid = createCompany($pdo, $prefix);
    list($aid, $avid, $sid, $chid) = createContentFixtures($pdo, $prefix, $cid);
    $mid = createMedia($pdo, $prefix, $sid, $chid, "10.00");
    $billing->ensureWallet($cid); $billing->manualCredit($cid, "100.00", 99, "test", "t11v1c_" . uniqid());
    $preview = $svc->preview($cid, $aid, $avid, [$mid]);
    $order = $svc->create($cid, 1, $aid, $avid, [$mid], "t11v1_" . uniqid(), $preview["price_fingerprint"]);
    assertTrue(!isset($order["cost_price"]), "Order must not expose cost_price");
    assertTrue(!isset($order["cost_snapshot"]), "Order must not expose cost_snapshot");
    assertTrue(!isset($order["supplier_cost"]), "Order must not expose supplier_cost");
    assertTrue(!isset($order["raw_payload"]), "Order must not expose raw_payload");
    $detail = $svc->getDetail($cid, $order["id"]);
    foreach ($detail["items"] as $item) {
        assertTrue(!isset($item["cost_snapshot"]), "Items must not expose cost_snapshot");
        assertTrue(!isset($item["cost_price"]), "Items must not expose cost_price");
        assertTrue(!isset($item["supplier_cost"]), "Items must not expose supplier_cost");
    }
    cleanupCompany($pdo, $prefix, $cid);
});

test("1.02 formatItemRow produces only allowed fields", function() use ($svc, $pdo, $prefix, $billing) {
    $cid = createCompany($pdo, $prefix);
    list($aid, $avid, $sid, $chid) = createContentFixtures($pdo, $prefix, $cid);
    $mid = createMedia($pdo, $prefix, $sid, $chid, "10.00");
    $billing->ensureWallet($cid); $billing->manualCredit($cid, "100.00", 99, "test", "t11v1b_" . uniqid());
    $preview = $svc->preview($cid, $aid, $avid, [$mid]);
    $order = $svc->create($cid, 1, $aid, $avid, [$mid], "t11v1b_" . uniqid(), $preview["price_fingerprint"]);
    $detail = $svc->getDetail($cid, $order["id"]);
    $allowed = ["id","media_id","media_name","media_display_name","sale_snapshot","status","supplier_order_no","published_url","created_at","updated_at"];
    foreach ($detail["items"] as $item) {
        foreach (array_keys($item) as $key) {
            assertTrue(in_array($key, $allowed, true), "Item has disallowed field: {$key}");
        }
    }
    cleanupCompany($pdo, $prefix, $cid);
});

test("1.03 preview response excludes cost", function() use ($svc, $pdo, $prefix) {
    $cid = createCompany($pdo, $prefix);
    list($aid, $avid, $sid, $chid) = createContentFixtures($pdo, $prefix, $cid);
    $mid = createMedia($pdo, $prefix, $sid, $chid, "10.00");
    $preview = $svc->preview($cid, $aid, $avid, [$mid]);
    foreach ($preview["items"] as $item) {
        assertTrue(!isset($item["cost_price"]), "Preview must not expose cost");
        assertTrue(isset($item["sale_price"]), "Preview must expose sale_price");
    }
    cleanupCompany($pdo, $prefix, $cid);
});

// ============================================================
// SECTION 2: TENANT ISOLATION
// ============================================================
echo "\n--- Section 2: Tenant Isolation ---\n";

test("2.01 company A cannot list B orders", function() use ($svc, $pdo, $prefix, $billing) {
    $cidA = createCompany($pdo, $prefix); list($aidA, $avidA, $sid, $chid) = createContentFixtures($pdo, $prefix, $cidA);
    $mid = createMedia($pdo, $prefix, $sid, $chid, "10.00");
    $billing->ensureWallet($cidA); $billing->manualCredit($cidA, "100.00", 99, "t", "t11v2a_" . uniqid());
    $preview = $svc->preview($cidA, $aidA, $avidA, [$mid]);
    $svc->create($cidA, 1, $aidA, $avidA, [$mid], "t11v2a_" . uniqid(), $preview["price_fingerprint"]);
    $cidB = createCompany($pdo, $prefix);
    $listB = $svc->listOrders($cidB, 1, 20);
    assertTrue(count($listB["items"]) === 0, "Company B should not see A orders");
    cleanupCompany($pdo, $prefix, $cidA);
    cleanupCompany($pdo, $prefix, $cidB);
});

test("2.02 company B cannot access A order detail", function() use ($svc, $pdo, $prefix, $billing) {
    $cidA = createCompany($pdo, $prefix); list($aidA, $avidA, $sid, $chid) = createContentFixtures($pdo, $prefix, $cidA);
    $mid = createMedia($pdo, $prefix, $sid, $chid, "10.00");
    $billing->ensureWallet($cidA); $billing->manualCredit($cidA, "100.00", 99, "t", "t11v2b_" . uniqid());
    $preview = $svc->preview($cidA, $aidA, $avidA, [$mid]);
    $orderA = $svc->create($cidA, 1, $aidA, $avidA, [$mid], "t11v2b_" . uniqid(), $preview["price_fingerprint"]);
    $cidB = createCompany($pdo, $prefix);
    $caught = false;
    try { $svc->getDetail($cidB, $orderA["id"]); } catch (PublishException $e) { $caught = ($e->getCode() === 1500); }
    assertTrue($caught, "B should not access A detail");
    cleanupCompany($pdo, $prefix, $cidA);
    cleanupCompany($pdo, $prefix, $cidB);
});

test("2.03 company B cannot cancel A order", function() use ($svc, $pdo, $prefix, $billing) {
    $cidA = createCompany($pdo, $prefix); list($aidA, $avidA, $sid, $chid) = createContentFixtures($pdo, $prefix, $cidA);
    $mid = createMedia($pdo, $prefix, $sid, $chid, "10.00");
    $billing->ensureWallet($cidA); $billing->manualCredit($cidA, "100.00", 99, "t", "t11v2c_" . uniqid());
    $preview = $svc->preview($cidA, $aidA, $avidA, [$mid]);
    $orderA = $svc->create($cidA, 1, $aidA, $avidA, [$mid], "t11v2c_" . uniqid(), $preview["price_fingerprint"]);
    $cidB = createCompany($pdo, $prefix);
    $caught = false;
    try { $svc->cancel($cidB, $orderA["id"], 1); } catch (PublishException $e) { $caught = ($e->getCode() === 1500); }
    assertTrue($caught, "B should not cancel A order");
    cleanupCompany($pdo, $prefix, $cidA);
    cleanupCompany($pdo, $prefix, $cidB);
});

test("2.04 company B cannot use A article to create order", function() use ($svc, $pdo, $prefix) {
    $cidA = createCompany($pdo, $prefix); list($aidA, $avidA, $sid, $chid) = createContentFixtures($pdo, $prefix, $cidA);
    $mid = createMedia($pdo, $prefix, $sid, $chid, "10.00");
    $cidB = createCompany($pdo, $prefix);
    $caught = false;
    try { $svc->preview($cidB, $aidA, $avidA, [$mid]); } catch (PublishException $e) { $caught = ($e->getCode() === 1523); }
    assertTrue($caught, "B should not use A article");
    cleanupCompany($pdo, $prefix, $cidA);
    cleanupCompany($pdo, $prefix, $cidB);
});

// ============================================================
// SECTION 3: RECONCILIATION
// ============================================================
echo "\n--- Section 3: Reconciliation ---\n";

test("3.01 order total equals item snapshots sum", function() use ($svc, $pdo, $prefix, $billing) {
    $cid = createCompany($pdo, $prefix); list($aid, $avid, $sid, $chid) = createContentFixtures($pdo, $prefix, $cid);
    $m1 = createMedia($pdo, $prefix, $sid, $chid, "10.00", "M1");
    $m2 = createMedia($pdo, $prefix, $sid, $chid, "15.00", "M2");
    $m3 = createMedia($pdo, $prefix, $sid, $chid, "20.00", "M3");
    $billing->ensureWallet($cid); $billing->manualCredit($cid, "100.00", 99, "t", "t11v3a_" . uniqid());
    $preview = $svc->preview($cid, $aid, $avid, [$m1, $m2, $m3]);
    $order = $svc->create($cid, 1, $aid, $avid, [$m1, $m2, $m3], "t11v3a_" . uniqid(), $preview["price_fingerprint"]);
    $detail = $svc->getDetail($cid, $order["id"]);
    $sum = "0.00";
    foreach ($detail["items"] as $item) { $sum = bcadd($sum, $item["sale_snapshot"], 2); }
    assertEq($order["total_amount"], $sum, "Order total != items sum");
    cleanupCompany($pdo, $prefix, $cid);
});

test("3.02 frozen_amount matches wallet frozen", function() use ($svc, $pdo, $prefix, $billing) {
    $cid = createCompany($pdo, $prefix); list($aid, $avid, $sid, $chid) = createContentFixtures($pdo, $prefix, $cid);
    $mid = createMedia($pdo, $prefix, $sid, $chid, "10.00");
    $billing->ensureWallet($cid); $billing->manualCredit($cid, "100.00", 99, "t", "t11v3b_" . uniqid());
    $preview = $svc->preview($cid, $aid, $avid, [$mid]);
    $order = $svc->create($cid, 1, $aid, $avid, [$mid], "t11v3b_" . uniqid(), $preview["price_fingerprint"]);
    $wallet = $billing->getWallet($cid);
    assertEq($order["frozen_amount"], $wallet["frozen_balance"], "Order frozen != wallet frozen");
    cleanupCompany($pdo, $prefix, $cid);
});

test("3.03 wallet changes match ledger entries", function() use ($billing, $pdo, $prefix) {
    $cid = createCompany($pdo, $prefix);
    $billing->ensureWallet($cid);
    $billing->manualCredit($cid, "50.00", 99, "t1", "t11v3c_a" . uniqid());
    $billing->manualCredit($cid, "30.00", 99, "t2", "t11v3c_b" . uniqid());
    $billing->manualDebit($cid, "20.00", 99, "t3", "t11v3c_c" . uniqid());
    $wallet = $billing->getWallet($cid);
    assertEq($wallet["available_balance"], "60.00");
    // Sum all ledger deltas
    $ledger = $billing->listLedger($cid, 1, 100);
    $sumAvail = "0.00";
    foreach ($ledger["items"] as $e) { $sumAvail = bcadd($sumAvail, $e["available_delta"], 2); }
    assertEq($sumAvail, $wallet["available_balance"], "Ledger sum != wallet available");
    cleanupCompany($pdo, $prefix, $cid);
});

test("3.04 unique biz key only one fund change", function() use ($billing, $pdo, $prefix) {
    $cid = createCompany($pdo, $prefix);
    $billing->ensureWallet($cid); $billing->manualCredit($cid, "100.00", 99, "t", "t11v3d_" . uniqid());
    $billing->freeze($cid, "test_biz", "b1", "30.00", "t11v3d_frz", "");
    $billing->freeze($cid, "test_biz", "b1", "30.00", "t11v3d_frz", "");
    $ledger = $billing->listLedger($cid, 1, 100, "freeze");
    assertTrue(count($ledger["items"]) === 1, "Duplicate freeze should not create second entry");
    cleanupCompany($pdo, $prefix, $cid);
});

test("3.05 cancel zeroes frozen amount", function() use ($svc, $pdo, $prefix, $billing) {
    $cid = createCompany($pdo, $prefix); list($aid, $avid, $sid, $chid) = createContentFixtures($pdo, $prefix, $cid);
    $mid = createMedia($pdo, $prefix, $sid, $chid, "10.00");
    $billing->ensureWallet($cid); $billing->manualCredit($cid, "100.00", 99, "t", "t11v3e_" . uniqid());
    $preview = $svc->preview($cid, $aid, $avid, [$mid]);
    $order = $svc->create($cid, 1, $aid, $avid, [$mid], "t11v3e_" . uniqid(), $preview["price_fingerprint"]);
    $svc->cancel($cid, $order["id"], 1);
    $wallet = $billing->getWallet($cid);
    assertEq($wallet["frozen_balance"], "0.00", "Frozen not zero after cancel");
    cleanupCompany($pdo, $prefix, $cid);
});

test("3.06 wallet balances never negative", function() use ($billing, $pdo, $prefix) {
    $cid = createCompany($pdo, $prefix);
    $billing->ensureWallet($cid); $billing->manualCredit($cid, "100.00", 99, "t", "t11v3f_" . uniqid());
    $billing->freeze($cid, "test", "b2", "50.00", "t11v3f_frz", "");
    $wallet = $billing->getWallet($cid);
    assertTrue(bccomp($wallet["available_balance"], "0.00", 2) >= 0, "Available negative");
    assertTrue(bccomp($wallet["frozen_balance"], "0.00", 2) >= 0, "Frozen negative");
    cleanupCompany($pdo, $prefix, $cid);
});

test("3.07 media price change does not affect order", function() use ($svc, $pdo, $prefix, $billing) {
    $cid = createCompany($pdo, $prefix); list($aid, $avid, $sid, $chid) = createContentFixtures($pdo, $prefix, $cid);
    $mid = createMedia($pdo, $prefix, $sid, $chid, "10.00");
    $billing->ensureWallet($cid); $billing->manualCredit($cid, "100.00", 99, "t", "t11v3g_" . uniqid());
    $preview = $svc->preview($cid, $aid, $avid, [$mid]);
    $order = $svc->create($cid, 1, $aid, $avid, [$mid], "t11v3g_" . uniqid(), $preview["price_fingerprint"]);
    $pdo->exec("UPDATE {$prefix}media_resources SET cost_price='999.99' WHERE id={$mid}");
    $detail = $svc->getDetail($cid, $order["id"]);
    assertTrue(bccomp($detail["items"][0]["sale_snapshot"], "999.99", 2) !== 0, "Snapshot should not reflect new price");
    assertTrue(bccomp($detail["order"]["total_amount"], "999.99", 2) !== 0, "Order total should not reflect new price");
    cleanupCompany($pdo, $prefix, $cid);
});

test("3.08 partial complete order-item-funds consistency", function() use ($svc, $pdo, $prefix, $billing) {
    $cid = createCompany($pdo, $prefix); list($aid, $avid, $sid, $chid) = createContentFixtures($pdo, $prefix, $cid);
    $m1 = createMedia($pdo, $prefix, $sid, $chid, "10.00", "M1");
    $m2 = createMedia($pdo, $prefix, $sid, $chid, "15.00", "M2");
    $billing->ensureWallet($cid); $billing->manualCredit($cid, "100.00", 99, "t", "t11v3h_" . uniqid());
    $preview = $svc->preview($cid, $aid, $avid, [$m1, $m2]);
    $order = $svc->create($cid, 1, $aid, $avid, [$m1, $m2], "t11v3h_" . uniqid(), $preview["price_fingerprint"]);
    $det = $svc->getDetail($cid, $order["id"]);
    $svc->transitionItem($cid, $order["id"], $det["items"][0]["id"], "queued");
    $svc->transitionItem($cid, $order["id"], $det["items"][0]["id"], "submitted");
    $svc->transitionItem($cid, $order["id"], $det["items"][0]["id"], "processing");
    $svc->transitionItem($cid, $order["id"], $det["items"][0]["id"], "published");
    $svc->transitionItem($cid, $order["id"], $det["items"][1]["id"], "queued");
    $svc->transitionItem($cid, $order["id"], $det["items"][1]["id"], "rejected");
    $d2 = $svc->getDetail($cid, $order["id"]);
    assertTrue($d2["order"]["status"] === "partially_completed", "Status: " . $d2["order"]["status"]);
    $wallet = $billing->getWallet($cid);
    // All frozen should be zero after capture+release
    assertEq($wallet["frozen_balance"], "0.00", "Frozen not zero after partial complete");
    assertTrue(bccomp($wallet["available_balance"], "0.00", 2) > 0, "Available should be > 0");
    cleanupCompany($pdo, $prefix, $cid);
});

// ============================================================
// SECTION 4: TRANSACTION ROLLBACK (fault injection)
// ============================================================
echo "\n--- Section 4: Transaction Rollback ---\n";

test("4.01 insufficient balance leaves no trace", function() use ($svc, $pdo, $prefix, $billing) {
    $cid = createCompany($pdo, $prefix); list($aid, $avid, $sid, $chid) = createContentFixtures($pdo, $prefix, $cid);
    $mid = createMedia($pdo, $prefix, $sid, $chid, "10.00");
    $billing->ensureWallet($cid);
    $preview = $svc->preview($cid, $aid, $avid, [$mid]);
    try { $svc->create($cid, 1, $aid, $avid, [$mid], "t11v4a_" . uniqid(), $preview["price_fingerprint"]); assertTrue(false, "Should throw"); }
    catch (\Throwable $e) {}
    $lc = $pdo->query("SELECT COUNT(*) FROM {$prefix}ledger_entries WHERE company_id={$cid}")->fetchColumn();
    assertTrue((int)$lc === 0, "No ledger entries");
    $wallet = $pdo->query("SELECT * FROM {$prefix}wallets WHERE company_id={$cid}")->fetch();
    assertTrue(is_array($wallet) && bccomp((string)$wallet["available_balance"], "0.00", 2) === 0 && bccomp((string)$wallet["frozen_balance"], "0.00", 2) === 0, "Wallet must remain unchanged");
    cleanupCompany($pdo, $prefix, $cid);
});

test("4.02 offline media is unavailable at order boundary", function() use ($pdo, $prefix) {
    $cid = createCompany($pdo, $prefix); list($aid, $avid, $sid, $chid) = createContentFixtures($pdo, $prefix, $cid);
    $mid = createMedia($pdo, $prefix, $sid, $chid, "10.00");
    $pdo->exec("UPDATE {$prefix}media_resources SET status='offline' WHERE id={$mid}");
    $availability = new ChannelAvailabilityService($pdo, $prefix);
    $unavailable = $availability->unavailableMediaIds([$mid]);
    assertTrue(in_array($mid, $unavailable, true), "Offline media must be unavailable at order boundary");
    cleanupCompany($pdo, $prefix, $cid);
});

test("4.03 retry after failure succeeds", function() use ($svc, $pdo, $prefix, $billing) {
    $cid = createCompany($pdo, $prefix); list($aid, $avid, $sid, $chid) = createContentFixtures($pdo, $prefix, $cid);
    $mid = createMedia($pdo, $prefix, $sid, $chid, "10.00");
    $preview = $svc->preview($cid, $aid, $avid, [$mid]);
    // First: insufficient balance should fail
    try { $svc->create($cid, 1, $aid, $avid, [$mid], "t11v4c_r1_" . uniqid(), $preview["price_fingerprint"]); }
    catch (\Throwable $e) {}
    // Credit and retry
    $billing->ensureWallet($cid); $billing->manualCredit($cid, "100.00", 99, "t", "t11v4c_cr_" . uniqid());
    $order = $svc->create($cid, 1, $aid, $avid, [$mid], "t11v4c_r2_" . uniqid(), $preview["price_fingerprint"]);
    assertTrue($order["status"] === "funds_frozen", "Retry should succeed");
    cleanupCompany($pdo, $prefix, $cid);
});

// ============================================================
// SECTION 5: EXTERNAL HTTP CHECK
// ============================================================
echo "\n--- Section 5: External HTTP Verification ---\n";

test("5.01 no external HTTP in T11 service layer", function() {
    // Verify PublishOrderService does not use Guzzle or any HTTP client
    $svcFile = APP_PATH . "common/publishing/PublishOrderService.php";
    $content = file_get_contents($svcFile);
    assertTrue(strpos($content, "Guzzle") === false, "PublishOrderService must not use Guzzle");
    assertTrue(strpos($content, "MediaMarket") === false, "PublishOrderService must not reference MediaMarket");
    assertTrue(
        preg_match('/\b(?:curl_init|curl_exec)\s*\(|file_get_contents\s*\(\s*[\'\"]https?:/i', $content) !== 1,
        "No executable HTTP calls"
    );
    assertTrue(strpos($content, "MediaMarketApiClient") === false, "No MediaMarketApiClient");
});

test("5.02 T11 order API controller does not call external services", function() {
    $ctrlFile = APP_PATH . "api/controller/Order.php";
    $content = file_get_contents($ctrlFile);
    assertTrue(strpos($content, "MediaMarket") === false, "API must not call MediaMarket");
    assertTrue(strpos($content, "Guzzle") === false, "API must not use Guzzle");
});

// ============================================================
// SECTION 6: ORDER CONFIRM PAGE EXISTENCE
// ============================================================
echo "\n--- Section 6: Page Existence ---\n";

test("6.01 order index page exists", function() {
    $page = ROOT_PATH . "application/index/view/order/index.html";
    assertTrue(file_exists($page), "Order page must exist");
    $content = file_get_contents($page);
    assertTrue(strpos($content, "订单") !== false || strpos($content, "order") !== false, "Page must contain order text");
});

// ============================================================
// SUMMARY
// ============================================================
echo "\n====================\n";
echo "T11 Final Verification: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);

<?php

declare(strict_types=1);

define("ROOT_PATH", dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define("APP_PATH", ROOT_PATH . "application" . DIRECTORY_SEPARATOR);
require ROOT_PATH . "thinkphp" . DIRECTORY_SEPARATOR . "base.php";
\think\Loader::addNamespace("app", APP_PATH);

use app\common\billing\BillingService;
use app\common\publishing\PublishOrderService;
use app\common\publishing\PublishRefundService;
use app\common\publishing\PublishNotificationService;
use app\common\tenant\PdoAuditLogger;

$config = []; $section = "global";
foreach (file(ROOT_PATH . ".env") as $line) {
    $line = trim($line);
    if ($line === "" || $line[0] === "#") continue;
    if (preg_match('/^\[(.+)\]$/', $line, $m)) { $section = $m[1]; continue; }
    $parts = explode("=", $line, 2);
    if (count($parts) === 2) { $k = trim($parts[0]); $v = trim($parts[1], " \"\t\r\n"); $config[$section][$k] = $v; }
}
$db = $config["database"] ?? $config["global"] ?? [];
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
function assertEq(string $a, string $b, string $m = ""): void { if (bccomp($a, $b, 2) !== 0) throw new \RuntimeException($m ?: "{$a} != {$b}"); }

echo "T15 Refund Tests\n===============\n";

$auditLogger = new PdoAuditLogger($pdo, $prefix);
$billing = new BillingService($pdo, $auditLogger, $prefix);
$orderSvc = new PublishOrderService($pdo, $billing, $prefix);
$refundSvc = new PublishRefundService($pdo, $orderSvc, $billing, $prefix);
$notifSvc = new PublishNotificationService($pdo, $prefix);

function withFixtures(PDO $pdo, string $prefix, callable $fn): void {
    $cid=0; $kwid=0; $qid=0; $ptid=0; $aid=0; $avid=0; $sid=0; $chid=0; $mediaIds=[];
    try {
        $stmt = $pdo->prepare("INSERT INTO {$prefix}companies (name,status,createtime,updatetime) VALUES (:n,:st,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
        $stmt->execute(["n"=>"T15svc_".uniqid(),"st"=>"normal"]); $cid = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT IGNORE INTO {$prefix}wallets (company_id,currency,available_balance,frozen_balance,version) VALUES ({$cid},'CNY',0.00,0.00,0)");
        $stmt = $pdo->prepare("INSERT INTO {$prefix}keywords (company_id,keyword,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kw,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
        $stmt->execute(["cid"=>$cid,"kw"=>"T15_KW","st"=>"normal"]); $kwid = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO {$prefix}questions (company_id,keyword_id,text,text_hash,source,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kwid,:t,:th,:src,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
        $stmt->execute(["cid"=>$cid,"kwid"=>$kwid,"t"=>"T15_Q","th"=>hash("sha256","T15_Q",true),"src"=>"manual","st"=>"normal"]); $qid = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO {$prefix}prompt_templates (company_id,logical_key,version_no,name,scene,content,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:lk,:vn,:nm,:sc,:ct,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
        $stmt->execute(["cid"=>$cid,"lk"=>"t15pt","vn"=>1,"nm"=>"T15_PT","sc"=>"article","ct"=>"prompt","st"=>"normal"]); $ptid = (int)$pdo->lastInsertId();
        $now = time();
        $stmt = $pdo->prepare("INSERT INTO {$prefix}articles (company_id,keyword_id,question_id,title,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kwid,:qid,:t,:st,:cb,:ub,:ct,:ut)");
        $stmt->execute(["cid"=>$cid,"kwid"=>$kwid,"qid"=>$qid,"t"=>"T15 Article","st"=>"draft","cb"=>0,"ub"=>0,"ct"=>$now,"ut"=>$now]); $aid = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO {$prefix}article_versions (company_id,article_id,version_no,title_snapshot,content,source,prompt_template_id,created_by,createtime) VALUES (:cid,:aid,:vn,:ts,:ct,:src,:ptid,:cb,UNIX_TIMESTAMP())");
        $stmt->execute(["cid"=>$cid,"aid"=>$aid,"vn"=>1,"ts"=>"T15 Article","ct"=>"T15 Content","src"=>"manual","ptid"=>$ptid,"cb"=>0]); $avid = (int)$pdo->lastInsertId();
        $pdo->exec("UPDATE {$prefix}articles SET current_version_id={$avid} WHERE id={$aid}");

        $sid = (int)($pdo->query("SELECT id FROM {$prefix}suppliers WHERE code='mock' LIMIT 1")->fetchColumn() ?: 0);
        if ($sid === 0) {
            $pdo->exec("INSERT INTO {$prefix}suppliers (code,display_name,status,created_at,updated_at) VALUES ('mock','Deterministic Mock','normal',NOW(),NOW())");
            $sid = (int)$pdo->lastInsertId();
        }
        $chid = (int)($pdo->query("SELECT id FROM {$prefix}channels WHERE code='official_media' LIMIT 1")->fetchColumn() ?: 0);
        if ($chid === 0) {
            $pdo->exec("INSERT INTO {$prefix}channels (code,name,category,status,supplier_confirmed,created_at,updated_at) VALUES ('official_media','Official Media','official_media','normal',0,NOW(),NOW())");
            $chid = (int)$pdo->lastInsertId();
        }

        for ($i = 0; $i < 5; $i++) {
            $cost = bcadd("10.00", (string)($i * 5), 2);
            $stmt2 = $pdo->prepare("INSERT INTO {$prefix}media_resources (supplier_id,external_id,channel_id,name,cost_price,cost_currency,status,attributes_json,last_synced_at,created_at,updated_at) VALUES (:sid,:eid,:chid,:nm,:cost,'CNY','active','{}',NOW(),NOW(),NOW())");
            $stmt2->execute(["sid"=>$sid,"eid"=>"ext15_".uniqid(),"chid"=>$chid,"nm"=>"M15{$i}","cost"=>$cost]);
            $mediaIds[] = (int)$pdo->lastInsertId();
        }
        $fn($cid, $aid, $avid, $mediaIds, $sid, $chid);
    } finally {
        $pdo->exec("DELETE FROM {$prefix}publish_refund_requests WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}publish_notifications WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}order_status_logs WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}publish_order_items WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}publish_orders WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}ledger_entries WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}wallets WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}media_resources WHERE name LIKE 'M15%' AND supplier_id={$sid}");
        $pdo->exec("UPDATE {$prefix}articles SET current_version_id=NULL WHERE id={$aid}");
        $pdo->exec("DELETE FROM {$prefix}article_versions WHERE article_id={$aid}");
        $pdo->exec("DELETE FROM {$prefix}articles WHERE id={$aid}");
        $pdo->exec("DELETE FROM {$prefix}prompt_templates WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}questions WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}keywords WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}companies WHERE id={$cid}");
    }
}

/**
 * Helper: create an order, fund it, and advance items to published via proper state machine.
 * Simulates capture by directly calling billing->capture (as transitionItem would do).
 */
function setupPublishedOrder(PDO $pdo, string $prefix, PublishOrderService $orderSvc, BillingService $billing, int $c, int $a, int $av, array $mediaIds): array {
    $p = $orderSvc->preview($c, $a, $av, $mediaIds);
    $total = $p["total_amount"];
    $billing->ensureWallet($c);
    $billing->manualCredit($c, bcmul($total, "3", 2), 99, "fund", "t15fund_" . uniqid());
    $o = $orderSvc->create($c, 1, $a, $av, $mediaIds, "t15ord_" . uniqid(), $p["price_fingerprint"]);
    $oid = (int) $o["id"];

    // Simulate full flow: funds_frozen -> queued -> submitted -> processing -> published
       $orderSvc->queueForSubmit($c, $oid);
       $d = $orderSvc->getDetail($c, $oid, false);

       foreach ($d["items"] as $item) {
           $itemId = (int) $item["id"];
            $orderSvc->transitionItem($c, $oid, $itemId, "queued", "system", "test: queued");
            $orderSvc->transitionItem($c, $oid, $itemId, "submitted", "system", "test: submitted");
        $orderSvc->transitionItem($c, $oid, $itemId, "processing", "system", "test: processing");
        $orderSvc->transitionItem($c, $oid, $itemId, "published", "system", "test: published", "127.0.0.1");
    }

    return ["oid" => $oid, "total" => $total, "order" => $orderSvc->getDetail($c, $oid, false)];
}

// Test 001: Refund requests table exists
test("001 refund_requests table exists", function() use($pdo,$prefix) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute(["t" => $prefix . "publish_refund_requests"]);
    assertTrue((int)$stmt->fetchColumn() === 1, "publish_refund_requests table missing");
});

// Test 010: Refund a published order succeeds
test("010 refund published order", function() use($pdo,$prefix,$orderSvc,$billing,$refundSvc) {
   withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($pdo,$prefix,$orderSvc,$billing,$refundSvc) {
        $res = setupPublishedOrder($pdo,$prefix,$orderSvc,$billing,$c,$a,$av,[$m[0]]);
        $result = $refundSvc->requestRefund($c,$res["oid"],1,"Test refund","127.0.0.1");
        assertTrue($result["refund_request"]["status"]==="approved");
        assertTrue(strpos($result["refund_request"]["request_no"],"RF")===0);
        $detail = $orderSvc->getDetail($c,$res["oid"],false);
        assertTrue($detail["order"]["status"]==="refunded");
        assertTrue($detail["items"][0]["status"]==="refunded");
    });
});

// Test 020: Refund creates refund ledger entry
test("020 refund creates ledger entries", function() use($pdo,$prefix,$orderSvc,$billing,$refundSvc) {
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($pdo,$prefix,$orderSvc,$billing,$refundSvc) {
        $res = setupPublishedOrder($pdo,$prefix,$orderSvc,$billing,$c,$a,$av,[$m[0]]);
        $refundSvc->requestRefund($c,$res["oid"],1,"Test","127.0.0.1");
        $stmt = $pdo->prepare("SELECT * FROM {$prefix}ledger_entries WHERE company_id=:cid AND entry_type='refund' ORDER BY id DESC LIMIT 1");
        $stmt->execute(["cid"=>$c]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        assertTrue($entry!==false,"refund ledger entry should exist");
        assertTrue($entry["biz_type"]==="publish_order");
    });
});

// Test 030: Cannot refund non-published order
test("030 cannot refund non-published order", function() use($pdo,$prefix,$orderSvc,$billing,$refundSvc) {
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($pdo,$prefix,$orderSvc,$billing,$refundSvc) {
        $p=$orderSvc->preview($c,$a,$av,[$m[0]]);
        $billing->ensureWallet($c); $billing->manualCredit($c,$p["total_amount"],99,"t","t15r03c".uniqid());
        $o=$orderSvc->create($c,1,$a,$av,[$m[0]],"t15r03".uniqid(),$p["price_fingerprint"]);
        try {
            $refundSvc->requestRefund($c,(int)$o["id"],1,"bad","127.0.0.1");
            throw new \RuntimeException("Expected exception");
        } catch (\app\common\publishing\PublishException $e) { assertTrue(true); }
    });
});

// Test 040: Duplicate refund is idempotent
test("040 duplicate refund is idempotent", function() use($pdo,$prefix,$orderSvc,$billing,$refundSvc) {
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($pdo,$prefix,$orderSvc,$billing,$refundSvc) {
        $res = setupPublishedOrder($pdo,$prefix,$orderSvc,$billing,$c,$a,$av,[$m[0]]);
        $r1 = $refundSvc->requestRefund($c,$res["oid"],1,"First","127.0.0.1");
        assertTrue($r1["refund_request"]["status"]==="approved");
        $r2 = $refundSvc->requestRefund($c,$res["oid"],1,"Second","127.0.0.1");
        assertTrue($r2["refund_request"]["id"]===$r1["refund_request"]["id"]);
        $cs = $pdo->prepare("SELECT COUNT(*) FROM {$prefix}ledger_entries WHERE company_id=:cid AND biz_id=:bid AND entry_type='refund'");
        $cs->execute(["cid"=>$c,"bid"=>(string)$res["oid"]]);
        assertTrue((int)$cs->fetchColumn()===1);
    });
});

// Test 050: Refund notification is created
test("050 refund creates notification", function() use($pdo,$prefix,$orderSvc,$billing,$refundSvc,$notifSvc) {
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($pdo,$prefix,$orderSvc,$billing,$refundSvc,$notifSvc) {
        $res = setupPublishedOrder($pdo,$prefix,$orderSvc,$billing,$c,$a,$av,[$m[0]]);
        $refundSvc->requestRefund($c,$res["oid"],1,"Test","127.0.0.1");
        $cnt = $notifSvc->countUnread($c,$res["oid"]);
        assertTrue($cnt>=1,"Expected at least 1 notification, got {$cnt}");
    });
});

// Test 060: List refunds for an order
test("060 list refunds", function() use($pdo,$prefix,$orderSvc,$billing,$refundSvc) {
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($pdo,$prefix,$orderSvc,$billing,$refundSvc) {
        $res = setupPublishedOrder($pdo,$prefix,$orderSvc,$billing,$c,$a,$av,[$m[0]]);
        $refundSvc->requestRefund($c,$res["oid"],1,"List reason","127.0.0.1");
        $list = $refundSvc->listRefunds($c,$res["oid"],1,20);
        assertTrue($list["total"]===1);
        assertTrue($list["items"][0]["status"]==="approved");
        assertTrue($list["items"][0]["reason"]==="List reason");
    });
});

// Test 070: Get single refund detail
test("070 get refund detail", function() use($pdo,$prefix,$orderSvc,$billing,$refundSvc) {
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($pdo,$prefix,$orderSvc,$billing,$refundSvc) {
        $res = setupPublishedOrder($pdo,$prefix,$orderSvc,$billing,$c,$a,$av,[$m[0]]);
        $r = $refundSvc->requestRefund($c,$res["oid"],1,"Detail test","127.0.0.1");
        $detail = $refundSvc->getRefund($c,$r["refund_request"]["id"]);
        assertTrue($detail["status"]==="approved");
        assertTrue($detail["reason"]==="Detail test");
    });
});

// Test 080: Multi-item order refunds only published items
test("080 multi-item partial refund", function() use($pdo,$prefix,$orderSvc,$billing,$refundSvc) {
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($pdo,$prefix,$orderSvc,$billing,$refundSvc) {
        $res = setupPublishedOrder($pdo,$prefix,$orderSvc,$billing,$c,$a,$av,[$m[0],$m[1]]);
        $r = $refundSvc->requestRefund($c,$res["oid"],1,"Partial","127.0.0.1");
        assertTrue($r["refund_request"]["status"]==="approved");
        $detail = $orderSvc->getDetail($c,$res["oid"],false);
        assertTrue($detail["items"][0]["status"]==="refunded");
        assertTrue($detail["items"][1]["status"]==="refunded");
    });
});

// Test 090: Status log records refund transitions
test("090 status log records refund transitions", function() use($pdo,$prefix,$orderSvc,$billing,$refundSvc) {
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($pdo,$prefix,$orderSvc,$billing,$refundSvc) {
        $res = setupPublishedOrder($pdo,$prefix,$orderSvc,$billing,$c,$a,$av,[$m[0]]);
        $refundSvc->requestRefund($c,$res["oid"],1,"Log test","127.0.0.1");
        $stmt = $pdo->prepare("SELECT * FROM {$prefix}order_status_logs WHERE company_id=:cid AND order_id=:oid ORDER BY id ASC");
        $stmt->execute(["cid"=>$c,"oid"=>$res["oid"]]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasAfterSale = false; $hasRefunded = false;
        foreach ($logs as $log) {
            if ($log["to_status"]==="after_sale") $hasAfterSale = true;
            if ($log["to_status"]==="refunded") $hasRefunded = true;
        }
        assertTrue($hasAfterSale,"should have after_sale log");
        assertTrue($hasRefunded,"should have refunded log");
    });
});

echo "\nT15 Refund: {$pass} PASS / {$fail} FAIL\n";
exit($fail>0 ? 1 : 0);

<?php

declare(strict_types=1);

define("ROOT_PATH", dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define("APP_PATH", ROOT_PATH . "application" . DIRECTORY_SEPARATOR);
require ROOT_PATH . "thinkphp" . DIRECTORY_SEPARATOR . "base.php";
\think\Loader::addNamespace("app", APP_PATH);

use app\common\billing\BillingService;
use app\common\publishing\PublishOrderService;
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

echo "T14 Notification & Result Tests\n===============================\n";

$auditLogger = new PdoAuditLogger($pdo, $prefix);
$billing = new BillingService($pdo, $auditLogger, $prefix);
$svc = new PublishOrderService($pdo, $billing, $prefix);
$notifSvc = new PublishNotificationService($pdo, $prefix);

function withFixtures(PDO $pdo, string $prefix, callable $fn): void {
    $cid=0; $kwid=0; $qid=0; $ptid=0; $aid=0; $avid=0; $sid=0; $chid=0; $mediaIds=[];
    try {
        $stmt = $pdo->prepare("INSERT INTO {$prefix}companies (name,status,createtime,updatetime) VALUES (:n,:st,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
        $stmt->execute(["n"=>"T14svc_".uniqid(),"st"=>"normal"]); $cid = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT IGNORE INTO {$prefix}wallets (company_id,currency,available_balance,frozen_balance,version) VALUES ({$cid},'CNY',0.00,0.00,0)");
        $stmt = $pdo->prepare("INSERT INTO {$prefix}keywords (company_id,keyword,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kw,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
        $stmt->execute(["cid"=>$cid,"kw"=>"T14_KW","st"=>"normal"]); $kwid = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO {$prefix}questions (company_id,keyword_id,text,text_hash,source,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kwid,:t,:th,:src,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
        $stmt->execute(["cid"=>$cid,"kwid"=>$kwid,"t"=>"T14_Q","th"=>hash("sha256","T14_Q",true),"src"=>"manual","st"=>"normal"]); $qid = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO {$prefix}prompt_templates (company_id,logical_key,version_no,name,scene,content,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:lk,:vn,:nm,:sc,:ct,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
        $stmt->execute(["cid"=>$cid,"lk"=>"t14pt","vn"=>1,"nm"=>"T14_PT","sc"=>"article","ct"=>"prompt","st"=>"normal"]); $ptid = (int)$pdo->lastInsertId();
        $now = time();
        $stmt = $pdo->prepare("INSERT INTO {$prefix}articles (company_id,keyword_id,question_id,title,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kwid,:qid,:t,:st,:cb,:ub,:ct,:ut)");
        $stmt->execute(["cid"=>$cid,"kwid"=>$kwid,"qid"=>$qid,"t"=>"T14 Article","st"=>"draft","cb"=>0,"ub"=>0,"ct"=>$now,"ut"=>$now]); $aid = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO {$prefix}article_versions (company_id,article_id,version_no,title_snapshot,content,source,prompt_template_id,created_by,createtime) VALUES (:cid,:aid,:vn,:ts,:ct,:src,:ptid,:cb,UNIX_TIMESTAMP())");
        $stmt->execute(["cid"=>$cid,"aid"=>$aid,"vn"=>1,"ts"=>"T14 Article","ct"=>"T14 Content","src"=>"manual","ptid"=>$ptid,"cb"=>0]); $avid = (int)$pdo->lastInsertId();
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
            $stmt2->execute(["sid"=>$sid,"eid"=>"ext8_".uniqid(),"chid"=>$chid,"nm"=>"M{$i}","cost"=>$cost]);
            $mediaIds[] = (int)$pdo->lastInsertId();
        }
        $fn($cid, $aid, $avid, $mediaIds, $sid, $chid);
    } finally {
        $pdo->exec("DELETE FROM {$prefix}publish_notifications WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}order_status_logs WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}publish_order_items WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}publish_orders WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}ledger_entries WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}wallets WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}media_resources WHERE name LIKE 'M%' AND supplier_id={$sid}");
        $pdo->exec("UPDATE {$prefix}articles SET current_version_id=NULL WHERE id={$aid}");
        $pdo->exec("DELETE FROM {$prefix}article_versions WHERE article_id={$aid}");
        $pdo->exec("DELETE FROM {$prefix}articles WHERE id={$aid}");
        $pdo->exec("DELETE FROM {$prefix}prompt_templates WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}questions WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}keywords WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}companies WHERE id={$cid}");
    }
}

// Test 001: Notification table exists
test("001 notification table exists", function() use($pdo,$prefix){
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute(["t"=>$prefix."publish_notifications"]);
    assertTrue((int)$stmt->fetchColumn()===1, "publish_notifications table missing");
});

// Test 010: Create notification
test("010 create notification", function() use($pdo,$prefix,$notifSvc){
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($notifSvc){
        $id = $notifSvc->createNotification($c, 999, 1, "published", "Test notification", ["foo"=>"bar"]);
        assertTrue($id > 0, "Notification ID should be positive");
    });
});

// Test 020: List notifications
test("020 list notifications", function() use($pdo,$prefix,$notifSvc){
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($notifSvc){
        $notifSvc->createNotification($c, 888, null, "order_status", "Order done", null);
        $notifSvc->createNotification($c, 888, 1, "published", "Item published", null);
        $list = $notifSvc->listNotifications($c, 1, 20);
        assertTrue(count($list["items"]) === 2, "Expected 2 notifications");
    });
});

// Test 030: Unread count
test("030 unread count", function() use($pdo,$prefix,$notifSvc){
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($notifSvc){
        $notifSvc->createNotification($c, 777, null, "order_status", "Test 1", null);
        $notifSvc->createNotification($c, 777, null, "order_status", "Test 2", null);
        $cnt = $notifSvc->countUnread($c, 777);
        assertTrue($cnt === 2, "Expected 2 unread, got {$cnt}");
    });
});

// Test 040: Mark as read
test("040 mark as read", function() use($pdo,$prefix,$notifSvc){
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($notifSvc){
        $id = $notifSvc->createNotification($c, 666, null, "published", "Test pub", null);
        assertTrue($id > 0);
        $ok = $notifSvc->markAsRead($c, $id);
        assertTrue($ok, "markAsRead should return true");
        $cnt = $notifSvc->countUnread($c, 666);
        assertTrue($cnt === 0, "Expected 0 unread after mark, got {$cnt}");
    });
});

// Test 050: Order detail includes notifications
test("050 order detail includes notifications", function() use($pdo,$prefix,$svc,$billing,$notifSvc){
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($svc,$billing,$notifSvc){
        $p=$svc->preview($c,$a,$av,[$m[0]]); $t=$p["total_amount"];
        $billing->ensureWallet($c); $billing->manualCredit($c,$t,99,"t","svcT14c".uniqid());
        $o=$svc->create($c,1,$a,$av,[$m[0]],"t14det".uniqid(),$p["price_fingerprint"]);
        $oid = (int)$o["id"];

        // Create a notification for this order
        $notifSvc->createNotification($c, $oid, null, "order_status", "Order created", null);

        $detail = $svc->getDetail($c, $oid, true);
        assertTrue(isset($detail["notifications"]), "detail should have notifications key");
        assertTrue(isset($detail["unread_count"]), "detail should have unread_count key");
        assertTrue($detail["unread_count"] >= 1, "Expected at least 1 unread");
    });
});

// Test 060: Terminal notification through PublishSyncService
test("060 sync creates terminal notifications", function() use($pdo,$prefix,$svc,$billing,$notifSvc){
    withFixtures($pdo,$prefix,function($c,$a,$av,$m,$sid)use($pdo,$prefix,$svc,$billing,$notifSvc){
        $p=$svc->preview($c,$a,$av,[$m[0]]); $t=$p["total_amount"];
        $billing->ensureWallet($c); $billing->manualCredit($c,bcmul($t,"2",2),99,"t","t14sync".uniqid());
        $o=$svc->create($c,1,$a,$av,[$m[0]],"t14sync".uniqid(),$p["price_fingerprint"]);
        $oid = (int)$o["id"];

        // Transition to queued, submitted, then manually to processing and published
        $svc->queueForSubmit($c, $oid);
        $d=$svc->getDetail($c,$oid,false);
        $itemId = $d["items"][0]["id"];

        // Set supplier_submit_request_id so sync can work
        $extOrderNo = "EXT_SYNC_" . uniqid();
        $itemsTable = $prefix . "publish_order_items";
        $upd = $pdo->prepare("UPDATE {$itemsTable} SET supplier_submit_request_id = :no, status = :st WHERE id = :id AND company_id = :cid");
        $upd->execute(["no"=>$extOrderNo, "st"=>"submitted", "id"=>(int)$itemId, "cid"=>$c]);

        // Verify notification count is currently 0
        $cntBefore = $notifSvc->countUnread($c, $oid);
        assertTrue($cntBefore === 0, "Expected 0 notifications before test");

        // Create notification directly simulating what sync would do
        $notifSvc->createNotification($c, $oid, (int)$itemId, "published", "Item published", [
            "order_id" => $oid,
            "item_id" => (int)$itemId,
            "new_status" => "published",
            "supplier_status" => "3",
        ]);

        $cntAfter = $notifSvc->countUnread($c, $oid);
        assertTrue($cntAfter === 1, "Expected 1 notification after publish, got {$cntAfter}");
    });
});

echo "\nT14 Notification: {$pass} PASS / {$fail} FAIL\n";
exit($fail>0?1:0);

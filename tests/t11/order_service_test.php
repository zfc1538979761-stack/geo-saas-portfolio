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
    try { $f(); echo "PASS: {$n}\n"; $pass++; }
    catch (\Throwable $e) { echo "FAIL: {$n} - " . $e->getMessage() . "\n"; $fail++; }
}
function assertTrue(bool $c, string $m = ""): void { if (!$c) throw new \RuntimeException($m ?: "assertion failed"); }
function assertEq(string $a, string $b, string $m = ""): void { if (bccomp($a, $b, 2) !== 0) throw new \RuntimeException($m ?: "{$a} != {$b}"); }

echo "T11 Order Service Tests\n=======================\n";

$auditLogger = new PdoAuditLogger($pdo, $prefix);
$billing = new BillingService($pdo, $auditLogger, $prefix);
$svc = new PublishOrderService($pdo, $billing, $prefix);

function withFixtures(PDO $pdo, string $prefix, callable $fn): void {
    $cid=0; $kwid=0; $qid=0; $ptid=0; $aid=0; $avid=0; $sid=0; $chid=0; $mediaIds=[];
    try {
        // 1. company
        $stmt = $pdo->prepare("INSERT INTO {$prefix}companies (name,status,createtime,updatetime) VALUES (:n,:st,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
        $stmt->execute(["n"=>"T11svc_".uniqid(),"st"=>"normal"]); $cid = (int)$pdo->lastInsertId();
        // 2. wallet
        $pdo->exec("INSERT IGNORE INTO {$prefix}wallets (company_id,currency,available_balance,frozen_balance,version) VALUES ({$cid},'CNY',0.00,0.00,0)");
        // 3. keyword (has updated_by)
        $stmt = $pdo->prepare("INSERT INTO {$prefix}keywords (company_id,keyword,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kw,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
        $stmt->execute(["cid"=>$cid,"kw"=>"T11_KW","st"=>"normal"]); $kwid = (int)$pdo->lastInsertId();
        // 4. question (has updated_by)
        $stmt = $pdo->prepare("INSERT INTO {$prefix}questions (company_id,keyword_id,text,text_hash,source,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kwid,:t,:th,:src,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
        $stmt->execute(["cid"=>$cid,"kwid"=>$kwid,"t"=>"T11_Q","th"=>hash("sha256","T11_Q",true),"src"=>"manual","st"=>"normal"]); $qid = (int)$pdo->lastInsertId();
        // 5. prompt_template (has updated_by)
        $stmt = $pdo->prepare("INSERT INTO {$prefix}prompt_templates (company_id,logical_key,version_no,name,scene,content,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:lk,:vn,:nm,:sc,:ct,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
        $stmt->execute(["cid"=>$cid,"lk"=>"t11pt","vn"=>1,"nm"=>"T11_PT","sc"=>"article","ct"=>"prompt","st"=>"normal"]); $ptid = (int)$pdo->lastInsertId();
        // 6. article
        $now = time();
        $stmt = $pdo->prepare("INSERT INTO {$prefix}articles (company_id,keyword_id,question_id,title,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kwid,:qid,:t,:st,:cb,:ub,:ct,:ut)");
        $stmt->execute(["cid"=>$cid,"kwid"=>$kwid,"qid"=>$qid,"t"=>"T11 Article","st"=>"draft","cb"=>0,"ub"=>0,"ct"=>$now,"ut"=>$now]); $aid = (int)$pdo->lastInsertId();
        // 7. article_version
        $stmt = $pdo->prepare("INSERT INTO {$prefix}article_versions (company_id,article_id,version_no,title_snapshot,content,source,prompt_template_id,created_by,createtime) VALUES (:cid,:aid,:vn,:ts,:ct,:src,:ptid,:cb,UNIX_TIMESTAMP())");
        $stmt->execute(["cid"=>$cid,"aid"=>$aid,"vn"=>1,"ts"=>"T11 Article","ct"=>"T11 Content","src"=>"manual","ptid"=>$ptid,"cb"=>0]); $avid = (int)$pdo->lastInsertId();
        // 8. set current version
        $pdo->exec("UPDATE {$prefix}articles SET current_version_id={$avid} WHERE id={$aid}");

        // Query existing suppliers/channels (seeded by T08 migration)
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

        // Create media resources (uses created_at/updated_at, cost_currency, no createtime/updatetime)
        for ($i = 0; $i < 5; $i++) {
            $cost = bcadd("10.00", (string)($i * 5), 2);
            $stmt2 = $pdo->prepare("INSERT INTO {$prefix}media_resources (supplier_id,external_id,channel_id,name,cost_price,cost_currency,status,attributes_json,last_synced_at,created_at,updated_at) VALUES (:sid,:eid,:chid,:nm,:cost,'CNY','active','{}',NOW(),NOW(),NOW())");
            $stmt2->execute(["sid"=>$sid,"eid"=>"ext_".uniqid(),"chid"=>$chid,"nm"=>"M{$i}","cost"=>$cost]);
            $mediaIds[] = (int)$pdo->lastInsertId();
        }
        $fn($cid, $aid, $avid, $mediaIds, $sid, $chid);
    } finally {
        // Cleanup in reverse FK order
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

test("001 tables exist", function() use($pdo,$prefix){
    foreach(["publish_orders","publish_order_items","order_status_logs"] as $t){
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
        $stmt->execute(["t"=>$prefix.$t]);
        assertTrue((int)$stmt->fetchColumn()===1);
    }
});

test("010 preview single media", function() use($svc,$pdo,$prefix){
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($svc){
        $r=$svc->preview($c,$a,$av,[$m[0]]);
        assertTrue(count($r["items"])===1);
        assertTrue(bccomp($r["total_amount"],"0.00",2)>0);
        assertTrue($r["price_fingerprint"]!=="");
    });
});

test("011 preview multi total equals sum", function() use($svc,$pdo,$prefix){
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($svc){
        $r=$svc->preview($c,$a,$av,[$m[0],$m[1]]);
        assertTrue(count($r["items"])===2);
        $sum="0.00"; foreach($r["items"] as $it) $sum=bcadd($sum,$it["sale_price"],2);
        assertEq($r["total_amount"],$sum,"Total != sum");
    });
});

test("020 create order freezes once", function() use($svc,$billing,$pdo,$prefix){
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($svc,$billing){
        $p=$svc->preview($c,$a,$av,[$m[0]]); $t=$p["total_amount"];
        $billing->ensureWallet($c); $billing->manualCredit($c,$t,99,"t","svc20cr".uniqid());
        $o=$svc->create($c,1,$a,$av,[$m[0]],"svc20".uniqid(),$p["price_fingerprint"]);
        assertTrue($o["status"]==="funds_frozen");
        assertEq($billing->getWallet($c)["frozen_balance"],$t);
    });
});

test("030 idempotent create", function() use($svc,$billing,$pdo,$prefix){
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($svc,$billing){
        $p=$svc->preview($c,$a,$av,[$m[0]]); $t=$p["total_amount"];
        $billing->ensureWallet($c); $billing->manualCredit($c,$t,99,"t","svc30c".uniqid());
        $ik="svc30".uniqid(); $o1=$svc->create($c,1,$a,$av,[$m[0]],$ik,$p["price_fingerprint"]);
        $o2=$svc->create($c,1,$a,$av,[$m[0]],$ik,$p["price_fingerprint"]);
        assertTrue($o1["id"]==$o2["id"]);
    });
});

test("040 cancel releases funds", function() use($svc,$billing,$pdo,$prefix){
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($svc,$billing){
        $p=$svc->preview($c,$a,$av,[$m[0]]); $t=$p["total_amount"];
        $billing->ensureWallet($c); $billing->manualCredit($c,$t,99,"t","svc40c".uniqid());
        $o=$svc->create($c,1,$a,$av,[$m[0]],"svc40".uniqid(),$p["price_fingerprint"]);
        $o["id"]=(int)$o["id"];$cc=$svc->cancel($c,$o["id"],1);
        assertTrue($cc["status"]==="cancelled");
        assertEq($billing->getWallet($c)["frozen_balance"],"0.00");
    });
});

test("050 partial complete publish+reject", function() use($svc,$billing,$pdo,$prefix){
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($svc,$billing){
        $p=$svc->preview($c,$a,$av,[$m[0],$m[1]]); $t=$p["total_amount"];
        $billing->ensureWallet($c); $billing->manualCredit($c,bcmul($t,"2",2),99,"t","svc50c".uniqid());
        $o=$svc->create($c,1,$a,$av,[$m[0],$m[1]],"svc50".uniqid(),$p["price_fingerprint"]);
        $o["id"]=(int)$o["id"];$d=$svc->getDetail($c,$o["id"]);
        $svc->transitionItem($c,$o["id"],$d["items"][0]["id"],"queued");
        $svc->transitionItem($c,$o["id"],$d["items"][0]["id"],"submitted");
        $svc->transitionItem($c,$o["id"],$d["items"][0]["id"],"processing");
        $svc->transitionItem($c,$o["id"],$d["items"][0]["id"],"published");
        $svc->transitionItem($c,$o["id"],$d["items"][1]["id"],"queued");
        $svc->transitionItem($c,$o["id"],$d["items"][1]["id"],"rejected");
        
        $d2=$svc->getDetail($c,$o["id"]);
        assertTrue($d2["order"]["status"]==="partially_completed","Status: ".$d2["order"]["status"]);
        assertEq($billing->getWallet($c)["frozen_balance"],"0.00");
    });
});

test("070 list orders pagination", function() use($svc,$billing,$pdo,$prefix){
    withFixtures($pdo,$prefix,function($c,$a,$av,$m)use($svc,$billing){
        $p=$svc->preview($c,$a,$av,[$m[0]]); $t=$p["total_amount"];
        $billing->ensureWallet($c); $billing->manualCredit($c,bcmul($t,"6",2),99,"t","svc70c".uniqid());
        $svc->create($c,1,$a,$av,[$m[0]],"svc70a".uniqid(),$p["price_fingerprint"]);
        $svc->create($c,1,$a,$av,[$m[1]],"svc70b".uniqid(),$svc->preview($c,$a,$av,[$m[1]])["price_fingerprint"]);
        $svc->create($c,1,$a,$av,[$m[2]],"svc70c".uniqid(),$svc->preview($c,$a,$av,[$m[2]])["price_fingerprint"]);
        $r=$svc->listOrders($c,1,20);
        assertTrue(count($r["items"])===3,"Expected 3 orders, got ".count($r["items"]));
    });
});

echo "\nT11 Service: {$pass} PASS / {$fail} FAIL\n";
exit($fail>0?1:0);

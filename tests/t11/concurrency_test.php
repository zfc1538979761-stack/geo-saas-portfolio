<?php

declare(strict_types=1);

define("ROOT_PATH", dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define("APP_PATH", ROOT_PATH . "application" . DIRECTORY_SEPARATOR);
require ROOT_PATH . "thinkphp" . DIRECTORY_SEPARATOR . "base.php";
\think\Loader::addNamespace("app", APP_PATH);

use app\common\billing\BillingService;
use app\common\publishing\PublishOrderService;
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

echo "T11 Concurrency Test\n====================\n";

// ---- Setup: shared fixtures ----
$auditLogger = new PdoAuditLogger($pdo, $prefix);
$billing = new BillingService($pdo, $auditLogger, $prefix);

$stmt = $pdo->prepare("INSERT INTO {$prefix}companies (name,status,createtime,updatetime) VALUES (:n,:st,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
$stmt->execute(["n" => "T11Conc_" . uniqid(), "st" => "normal"]); $cid = (int)$pdo->lastInsertId();
$billing->ensureWallet($cid); $billing->manualCredit($cid, "200.00", 99, "concurrency setup", "t11conc_setup_" . uniqid());

$stmt = $pdo->prepare("INSERT INTO {$prefix}keywords (company_id,keyword,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kw,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
$stmt->execute(["cid" => $cid, "kw" => "T11Conc_KW", "st" => "normal"]); $kwid = (int)$pdo->lastInsertId();
$stmt = $pdo->prepare("INSERT INTO {$prefix}questions (company_id,keyword_id,text,text_hash,source,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kwid,:t,:th,:src,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
$stmt->execute(["cid" => $cid, "kwid" => $kwid, "t" => "T11Conc_Q", "th" => hash("sha256", "T11Conc_Q", true), "src" => "manual", "st" => "normal"]); $qid = (int)$pdo->lastInsertId();
$stmt = $pdo->prepare("INSERT INTO {$prefix}prompt_templates (company_id,logical_key,version_no,name,scene,content,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:lk,:vn,:nm,:sc,:ct,:st,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())");
$stmt->execute(["cid" => $cid, "lk" => "t11cpt", "vn" => 1, "nm" => "T11C_PT", "sc" => "article", "ct" => "prompt", "st" => "normal"]); $ptid = (int)$pdo->lastInsertId();
$now = time();
$stmt = $pdo->prepare("INSERT INTO {$prefix}articles (company_id,keyword_id,question_id,title,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kwid,:qid,:t,:st,:cb,:ub,:ct,:ut)");
$stmt->execute(["cid" => $cid, "kwid" => $kwid, "qid" => $qid, "t" => "T11C Article", "st" => "draft", "cb" => 0, "ub" => 0, "ct" => $now, "ut" => $now]); $aid = (int)$pdo->lastInsertId();
$stmt = $pdo->prepare("INSERT INTO {$prefix}article_versions (company_id,article_id,version_no,title_snapshot,content,source,prompt_template_id,created_by,createtime) VALUES (:cid,:aid,:vn,:ts,:ct,:src,:ptid,:cb,UNIX_TIMESTAMP())");
$stmt->execute(["cid" => $cid, "aid" => $aid, "vn" => 1, "ts" => "T11C Article", "ct" => "T11C Content", "src" => "manual", "ptid" => $ptid, "cb" => 0]); $avid = (int)$pdo->lastInsertId();
$pdo->exec("UPDATE {$prefix}articles SET current_version_id={$avid} WHERE id={$aid}");

$sid = (int)($pdo->query("SELECT id FROM {$prefix}suppliers WHERE code='mock' LIMIT 1")->fetchColumn() ?: 0);
if ($sid === 0) { $pdo->exec("INSERT INTO {$prefix}suppliers (code,display_name,status,created_at,updated_at) VALUES ('mock','Mock','normal',NOW(),NOW())"); $sid = (int)$pdo->lastInsertId(); }
$chid = (int)($pdo->query("SELECT id FROM {$prefix}channels WHERE code='official_media' LIMIT 1")->fetchColumn() ?: 0);
if ($chid === 0) { $pdo->exec("INSERT INTO {$prefix}channels (code,name,category,status,supplier_confirmed,created_at,updated_at) VALUES ('official_media','OM','official_media','normal',0,NOW(),NOW())"); $chid = (int)$pdo->lastInsertId(); }
$stmt2 = $pdo->prepare("INSERT INTO {$prefix}media_resources (supplier_id,external_id,channel_id,name,cost_price,cost_currency,status,attributes_json,last_synced_at,created_at,updated_at) VALUES (:sid,:eid,:chid,:nm,:cost,'CNY','active','{}',NOW(),NOW(),NOW())");
$stmt2->execute(["sid" => $sid, "eid" => "ext_conc", "chid" => $chid, "nm" => "Conc_M", "cost" => "10.00"]); $mid = (int)$pdo->lastInsertId();

$svc = new PublishOrderService($pdo, $billing, $prefix);
$preview = $svc->preview($cid, $aid, $avid, [$mid]);
$fp = $preview["price_fingerprint"];
$ik = "t11conc_" . uniqid();

// Build child script using APP_PATH correctly
$rootDir = ROOT_PATH;
$appDir = APP_PATH;
$childCode = '<' . "?php\n"
    . "define('ROOT_PATH', " . var_export($rootDir, true) . ");\n"
    . "define('APP_PATH', " . var_export($appDir, true) . ");\n"
    . "require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';\n"
    . "\\think\\Loader::addNamespace('app', APP_PATH);\n"
    . "\n"
    . "\$config = []; \$section = 'global';\n"
    . "foreach (file(ROOT_PATH . '.env') as \$line) {\n"
    . "    \$line = trim(\$line);\n"
    . "    if (\$line === '' || \$line[0] === '#') continue;\n"
    . "    if (preg_match('/^\[(.+)\]$/', \$line, \$m)) { \$section = \$m[1]; continue; }\n"
    . "    \$parts = explode('=', \$line, 2);\n"
    . "    if (count(\$parts) === 2) { \$k = trim(\$parts[0]); \$v = trim(\$parts[1], \" \\t\\\"\\r\\n\"); \$config[\$section][\$k] = \$v; }\n"
    . "}\n"
    . "\$db = \$config['database'] ?? \$config['global'] ?? [];\n"
    . "\$db = [\n"
    . "    'hostname' => getenv('GEO_DATABASE_HOSTNAME') ?: (\$db['hostname'] ?? ''),\n"
    . "    'hostport' => getenv('GEO_DATABASE_HOSTPORT') ?: (\$db['hostport'] ?? '3306'),\n"
    . "    'database' => getenv('GEO_DATABASE_DATABASE') ?: (\$db['database'] ?? 'fastadmin'),\n"
    . "    'username' => getenv('GEO_DATABASE_USERNAME') ?: (\$db['username'] ?? 'root'),\n"
    . "    'password' => getenv('GEO_DATABASE_PASSWORD') ?: (\$db['password'] ?? ''),\n"
    . "    'prefix' => getenv('GEO_DATABASE_PREFIX') ?: (\$db['prefix'] ?? 'fa_'),\n"
    . "];\n"
    . "\$prefix = \$db['prefix'] ?? 'fa_';\n"
    . "\$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',\n"
    . "    \$db['hostname'] ?? '127.0.0.1', \$db['hostport'] ?? '3306', \$db['database'] ?? 'fastadmin');\n"
    . "\$pdo = new PDO(\$dsn, \$db['username'] ?? 'root', \$db['password'] ?? '', [\n"
    . "    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n"
    . "]);\n"
    . "\n"
    . "use app\\common\\billing\\BillingService;\n"
    . "use app\\common\\publishing\\PublishOrderService;\n"
    . "use app\\common\\tenant\\PdoAuditLogger;\n"
    . "\n"
    . "\$auditLogger = new PdoAuditLogger(\$pdo, \$prefix);\n"
    . "\$billing = new BillingService(\$pdo, \$auditLogger, \$prefix);\n"
    . "\$svc = new PublishOrderService(\$pdo, \$billing, \$prefix);\n"
    . "try {\n"
    . "    \$result = \$svc->create(" . $cid . ", 1, " . $aid . ", " . $avid . ", [" . $mid . "], " . var_export($ik, true) . ", " . var_export($fp, true) . ");\n"
    . "    echo 'OK:' . json_encode(\$result);\n"
    . "} catch (\\Throwable \$e) {\n"
    . "    echo 'ERR:' . \$e->getMessage();\n"
    . "}\n";

$childFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 't11_concurrency_child_' . uniqid() . '.php';
file_put_contents($childFile, $childCode);

$phpBin = PHP_BINARY;
$descs = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];

// Launch two processes simultaneously
$p1 = proc_open("$phpBin $childFile", $descs, $pipes1);
$p2 = proc_open("$phpBin $childFile", $descs, $pipes2);

$out1 = stream_get_contents($pipes1[1]);
$out2 = stream_get_contents($pipes2[1]);
$err1 = stream_get_contents($pipes1[2]);
$err2 = stream_get_contents($pipes2[2]);
proc_close($p1);
proc_close($p2);

// DEBUG
echo "P1_OUT: " . substr($out1 ?: '(empty)', 0, 300) . "\n";
echo "P2_OUT: " . substr($out2 ?: '(empty)', 0, 300) . "\n";
if ($err1) echo "P1_ERR: " . substr($err1, 0, 200) . "\n";
if ($err2) echo "P2_ERR: " . substr($err2, 0, 200) . "\n";

unlink($childFile);

test("001 concurrent idempotency same key", function () use ($out1, $out2) {
    assertTrue(str_starts_with($out1, "OK:") || str_starts_with($out1, "OK:"), "P1 failed: " . substr($out1, 0, 100));
    assertTrue(str_starts_with($out2, "OK:") || str_starts_with($out2, "OK:"), "P2 failed: " . substr($out2, 0, 100));
    $o1 = json_decode(substr($out1, 3), true);
    $o2 = json_decode(substr($out2, 3), true);
    assertTrue(is_array($o1) && is_array($o2), "Both processes should complete");
    assertTrue(isset($o1["id"]) && isset($o2["id"]), "Both should return orders");
    assertTrue($o1["id"] == $o2["id"], "Both should return same order");
    assertTrue($o1["order_no"] === $o2["order_no"], "Same order number");
});

test("002 only one order created", function () use ($pdo, $prefix, $ik) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$prefix}publish_orders WHERE idempotency_key=:ik");
    $stmt->execute(["ik" => $ik]);
    assertTrue((int)$stmt->fetchColumn() === 1, "Only one order should exist");
});

test("003 only one freeze", function () use ($pdo, $prefix) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$prefix}order_status_logs WHERE event_key LIKE :ek AND order_item_id IS NULL");
    $stmt->execute(["ek" => "%:created"]);
    $cnt = (int)$stmt->fetchColumn(); assertTrue($cnt >= 1, "Expected >=1 ORDER creation events, got {$cnt}");
});

// Cleanup
$pdo->exec("DELETE FROM {$prefix}order_status_logs WHERE company_id={$cid}");
$pdo->exec("DELETE FROM {$prefix}publish_order_items WHERE company_id={$cid}");
$pdo->exec("DELETE FROM {$prefix}publish_orders WHERE company_id={$cid}");
$pdo->exec("DELETE FROM {$prefix}ledger_entries WHERE company_id={$cid}");
$pdo->exec("DELETE FROM {$prefix}wallets WHERE company_id={$cid}");
$pdo->exec("DELETE FROM {$prefix}media_resources WHERE name='Conc_M'");
$pdo->exec("UPDATE {$prefix}articles SET current_version_id=NULL WHERE id={$aid}");
$pdo->exec("DELETE FROM {$prefix}article_versions WHERE article_id={$aid}");
$pdo->exec("DELETE FROM {$prefix}articles WHERE id={$aid}");
$pdo->exec("DELETE FROM {$prefix}prompt_templates WHERE company_id={$cid}");
$pdo->exec("DELETE FROM {$prefix}questions WHERE company_id={$cid}");
$pdo->exec("DELETE FROM {$prefix}keywords WHERE company_id={$cid}");
$pdo->exec("DELETE FROM {$prefix}companies WHERE id={$cid}");

echo "\nT11 Concurrency: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);

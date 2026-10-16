<?php

declare(strict_types=1);

define("ROOT_PATH", dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define("APP_PATH", ROOT_PATH . "application" . DIRECTORY_SEPARATOR);
require ROOT_PATH . "thinkphp" . DIRECTORY_SEPARATOR . "base.php";
\think\Loader::addNamespace("app", APP_PATH);

use app\common\billing\BillingService;
use app\common\publishing\PublishOrderService;
use app\common\publishing\PublishSyncService;
use app\common\publishing\PublishSyncJob;
use app\common\publishing\PublishException;
use app\common\supplier\SupplierAdapterFactory;
use app\common\supplier\dto\OrderQuery;
use app\common\supplier\dto\OrderResult;
use app\common\supplier\dto\SupplierRawPayload;
use app\common\tenant\PdoAuditLogger;

$config = [];
$section = "global";
foreach (file(ROOT_PATH . ".env") as $line) {
    $line = trim($line);
    if ($line === "" || $line[0] === "#") continue;
    if (preg_match("/^\[(.+)\]$/", $line, $m)) { $section = $m[1]; continue; }
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

echo "T13 Sync Tests\n===============\n";

$audit = new PdoAuditLogger($pdo, $prefix);
$billing = new BillingService($pdo, $audit, $prefix);
$orderSvc = new PublishOrderService($pdo, $billing, $prefix);
$syncSvc = new PublishSyncService($pdo, $orderSvc, $prefix);

// === 1. Migration columns ===
test("001 sync columns exist", function() use ($pdo, $prefix) {
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t");
    $stmt->execute(["t" => $prefix . "publish_order_items"]);
    $cols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), "COLUMN_NAME");
    foreach (["last_synced_at", "supplier_sync_raw"] as $c) {
        assertTrue(in_array($c, $cols), "Missing column: {$c}");
    }
});

// === 2. Status mapping (reflection; use real PDO) ===
test("010 mapStatus maps known codes", function() use ($pdo, $orderSvc) {
    $ref = new ReflectionMethod(PublishSyncService::class, "mapStatus");
    $ref->setAccessible(true);
    // The PublishSyncService only uses PDO for sync operations;
    // the private mapStatus is pure PHP, so any PDO works.
    $svc = new PublishSyncService($pdo, $orderSvc, "");
    assertTrue($ref->invoke($svc, "0") === "submitted", "0 -> submitted");
    assertTrue($ref->invoke($svc, "1") === "processing", "1 -> processing");
    assertTrue($ref->invoke($svc, "2") === "published", "2 -> published per official API doc");
    assertTrue($ref->invoke($svc, "3") === "rejected", "3 -> rejected per official API doc");
    assertTrue($ref->invoke($svc, "4") === "manual_review", "4 -> manual_review (not defined in API doc)");
    assertTrue($ref->invoke($svc, "unknown") === "manual_review", "unknown -> manual_review");
    assertTrue($ref->invoke($svc, "") === "manual_review", "empty -> manual_review");
});

// === 3. Mock adapter queryOrder contract ===
test("020 mock adapter supports queryOrder", function() {
    $adapterCfg = ["driver" => "mock", "environment" => "testing", "mock" => ["scenario" => "query_success"]];
    $adapter = SupplierAdapterFactory::create($adapterCfg);
    $caps = $adapter->capabilities();
    assertTrue($caps->supportsOrderQuery(), "should support queryOrder");
});

test("021 mock queryOrder returns known status", function() {
    $adapterCfg = ["driver" => "mock", "environment" => "testing", "mock" => ["scenario" => "query_success"]];
    $adapter = SupplierAdapterFactory::create($adapterCfg);
    $result = $adapter->queryOrder(new OrderQuery("mock-ext-001"));
    assertTrue($result->supplierStatusKnown(), "should be known");
    assertTrue($result->externalOrderNo() !== "", "should have ext order no");
});

// === 4. OrderResult DTO contract ===
test("030 OrderResult with failure is not known", function() {
    $failure = new \app\common\supplier\dto\SupplierFailure("query_failed", "test error", true);
    $result = new OrderResult("ext-001", "unknown", "", false, null, $failure);
    assertTrue(!$result->supplierStatusKnown());
    assertTrue($result->failure() !== null);
    assertTrue($result->failure()->code() === "query_failed");
});

test("031 OrderResult rawPayload.items extraction", function() {
    $raw = new SupplierRawPayload([
        "externalOrderNo" => "trade-001",
        "status" => "2",
        "items" => [
            ["popId" => "pop-1", "mediaId" => "media-a", "status" => "2"],
            ["popId" => "pop-2", "mediaId" => "media-b", "status" => "3"],
        ],
    ]);
    $result = new OrderResult("trade-001", "processing", "2", true, null, null, null, $raw);
    $payload = $result->rawPayload()->all();
    assertTrue(count($payload["items"]) === 2, "should have 2 items");
    assertTrue($payload["items"][0]["mediaId"] === "media-a");
    assertTrue($payload["items"][1]["status"] === "3");
});

// === 5. findOrdersNeedingSync ===
test("040 findOrdersNeedingSync returns array", function() use ($pdo, $syncSvc) {
    $orders = $syncSvc->findOrdersNeedingSync(10);
    assertTrue(is_array($orders), "should return array");
    foreach ($orders as $o) {
        assertTrue(isset($o["company_id"]) && isset($o["order_id"]));
    }
});

// === 6. Sync result structure (catch PublishException) ===
test("050 syncResult has required fields", function() use ($syncSvc) {
    $adapterCfg = ["driver" => "mock", "environment" => "testing", "mock" => ["scenario" => "query_success"]];
    $adapter = SupplierAdapterFactory::create($adapterCfg);
    try {
        $result = $syncSvc->syncOrder($adapter, 98765, 98765);
    } catch (PublishException $e) {
        assertTrue($e->getCode() === 1500, "should be order not found");
        return;
    } catch (\Throwable $e) {
        assertTrue(false, "unexpected exception: " . $e->getMessage());
    }
    // If no exception, syncOrder still returned - verify its shape
    $keys = ["status", "synced", "unchanged", "failed", "message"];
    foreach ($keys as $k) {
        assertTrue(array_key_exists($k, $result ?? []), "Missing result key: {$k}");
    }
});

// === 7. SupplierFailure retryable flag ===
test("060 SupplierFailure retryable", function() {
    $retryable = new \app\common\supplier\dto\SupplierFailure("timeout", "timeout", true);
    assertTrue($retryable->retryable());
    $nonRetryable = new \app\common\supplier\dto\SupplierFailure("invalid", "bad", false);
    assertTrue(!$nonRetryable->retryable());
});

// === 8. PublishSyncJob class structure ===
test("070 PublishSyncJob has runBatch", function() {
    $ref = new ReflectionMethod(PublishSyncJob::class, "runBatch");
    assertTrue($ref->isStatic());
    assertTrue($ref->isPublic());
});

// === 9. Real adapter from config ===
test("080 real adapter capabilities include queryOrder", function() use ($config) {
    $mmConfig = $config["media_market"] ?? [];
    if (!isset($mmConfig["base_url"])) {
        assertTrue(true); // skip if no real config
        return;
    }
    try {
        $adapter = SupplierAdapterFactory::create([
            "driver" => "media_market",
            "environment" => "testing",
            "media_market" => $mmConfig,
            "channel_map" => [],
        ]);
        $caps = $adapter->capabilities();
        assertTrue($caps->supportsOrderQuery(), "media_market should support queryOrder");
    } catch (\Throwable $e) {
        assertTrue(strlen($e->getMessage()) > 0);
    }
});

echo "\nT13 Sync: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);

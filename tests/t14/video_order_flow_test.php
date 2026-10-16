<?php

declare(strict_types=1);

define("ROOT_PATH", dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define("APP_PATH", ROOT_PATH . "application" . DIRECTORY_SEPARATOR);
require ROOT_PATH . "thinkphp" . DIRECTORY_SEPARATOR . "base.php";
\think\Loader::addNamespace("app", APP_PATH);

use app\common\billing\BillingService;
use app\common\publishing\PublishOrderService;
use app\common\publishing\PublishSubmitJob;
use app\common\publishing\PublishSubmitService;
use app\common\publishing\PublishSyncService;
use app\common\supplier\MockSupplierAdapter;
use app\common\supplier\dto\OrderQuery;
use app\common\supplier\dto\OrderResult;
use app\common\supplier\dto\OrderStatus;
use app\common\supplier\dto\OrderSubmitItemResult;
use app\common\supplier\dto\OrderSubmitRequest;
use app\common\supplier\dto\OrderSubmitResult;
use app\common\supplier\dto\SupplierRawPayload;
use app\common\tenant\PdoAuditLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;

final class FakeVideoSupplierAdapter implements \app\common\supplier\contract\SupplierAdapter
{
    public int $submitCalls = 0;
    public int $queryCalls = 0;
    public string $queryScenario = "rejected";
    public bool $submitRejected = false;
    public int $publishedQueriesBeforePublished = 1;
    public string $externalMediaId = "ee3b8474ca8741a3aa0ac6c3d06e9899";
    private MockSupplierAdapter $mock;

    public function __construct()
    {
        $this->mock = new MockSupplierAdapter("testing", ["scenario" => "default"]);
    }

    public function supplierCode(): string
    {
        return "media_market_video_test";
    }

    public function capabilities(): \app\common\supplier\dto\SupplierCapabilities
    {
        return $this->mock->capabilities();
    }

    public function listChannels(): array
    {
        return $this->mock->listChannels();
    }

    public function fetchMedia(\app\common\supplier\dto\MediaQuery $query): \app\common\supplier\dto\MediaPage
    {
        return $this->mock->fetchMedia($query);
    }

    public function queryMediaChanges(\app\common\supplier\dto\MediaChangeQuery $query): \app\common\supplier\dto\MediaChangePage
    {
        return $this->mock->queryMediaChanges($query);
    }

    public function parseCallback(\app\common\supplier\dto\CallbackEnvelope $envelope): \app\common\supplier\dto\ParsedCallback
    {
        return $this->mock->parseCallback($envelope);
    }

    public function submitOrder(OrderSubmitRequest $request): OrderSubmitResult
    {
        $this->submitCalls++;
        $itemResults = [];
        foreach ($request->externalMediaIds() as $externalMediaId) {
            if ($this->submitRejected) {
                $itemResults[] = new OrderSubmitItemResult($externalMediaId, OrderStatus::REJECTED);
            } else {
                $itemResults[] = new OrderSubmitItemResult($externalMediaId, OrderStatus::SUBMITTED);
            }
        }
        return new OrderSubmitResult(
            $request->idempotencyKey(),
            "0812144350452034",
            $this->submitRejected ? OrderStatus::REJECTED : OrderStatus::SUBMITTED,
            $this->submitRejected ? "3" : "0",
            null,
            null,
            $itemResults,
            "video-pop-1"
        );
    }

    public function queryOrder(OrderQuery $query): OrderResult
    {
        $this->queryCalls++;
        if ($this->queryScenario === "published") {
            if ($this->queryCalls <= $this->publishedQueriesBeforePublished) {
                $items = [[
                    "mediaId" => $this->externalMediaId,
                    "popId" => "video-pop-1",
                    "publishedUrl" => null,
                    "status" => "1",
                ]];
                return new OrderResult(
                    (string) ($query->externalOrderNo() ?? "video-pop-1"),
                    OrderStatus::PROCESSING,
                    "1",
                    true,
                    null,
                    null,
                    null,
                    new SupplierRawPayload(["items" => $items])
                );
            }
            $items = [[
                "mediaId" => $this->externalMediaId,
                "popId" => "video-pop-1",
                "publishedUrl" => "https://www.douyin.com/user/video-result",
                "status" => "2",
            ]];
            return new OrderResult(
                (string) ($query->externalOrderNo() ?? "video-pop-1"),
                OrderStatus::PUBLISHED,
                "2",
                true,
                "https://www.douyin.com/user/video-result",
                null,
                null,
                new SupplierRawPayload(["items" => $items])
            );
        }
        $items = [[
            "mediaId" => $this->externalMediaId,
            "popId" => "video-pop-1",
            "publishedUrl" => null,
            "refuseReason" => "内容不合适",
            "status" => "3",
        ]];
        return new OrderResult(
            (string) ($query->externalOrderNo() ?? "video-pop-1"),
            OrderStatus::REJECTED,
            "3",
            true,
            null,
            null,
            null,
            new SupplierRawPayload(["items" => $items])
        );
    }
}

$config = [];
$section = "global";
foreach (file(ROOT_PATH . ".env") as $line) {
    $line = trim($line);
    if ($line === "" || $line[0] === "#") {
        continue;
    }
    if (preg_match("/^\[(.+)\]$/", $line, $m)) {
        $section = $m[1];
        continue;
    }
    $parts = explode("=", $line, 2);
    if (count($parts) === 2) {
        $config[$section][trim($parts[0])] = trim($parts[1], " \t\"\r\n");
    }
}
$db = $config["database"] ?? $config["global"] ?? [];
$prefix = $db["prefix"] ?? "fa_";
$dsn = sprintf(
    "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
    $db["hostname"] ?? "127.0.0.1",
    $db["hostport"] ?? "3306",
    $db["database"] ?? "fastadmin"
);
$pdo = new PDO($dsn, $db["username"] ?? "root", $db["password"] ?? "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pass = 0;
$fail = 0;
function test(string $n, callable $f): void
{
    global $pass, $fail;
    try {
        $f();
        echo "PASS: {$n}\n";
        $pass++;
    } catch (\Throwable $e) {
        echo "FAIL: {$n} - " . $e->getMessage() . "\n";
        $fail++;
    }
}
function assertTrue(bool $c, string $m = ""): void
{
    if (!$c) {
        throw new \RuntimeException($m ?: "assertion failed");
    }
}

$audit = new PdoAuditLogger($pdo, $prefix);
$billing = new BillingService($pdo, $audit, $prefix);
$orderSvc = new PublishOrderService($pdo, $billing, $prefix);
$submitSvc = new PublishSubmitService($pdo, $orderSvc, $billing, $prefix);
$syncSvc = new PublishSyncService($pdo, $orderSvc, $prefix);

const VIDEO_EXTERNAL_ID = "ee3b8474ca8741a3aa0ac6c3d06e9899";

function withVideoFixtures(PDO $pdo, string $prefix, callable $fn, string $catalog = "/video", bool $priceConfirmed = true): void
{
    $cid = 0;
    $kwid = 0;
    $qid = 0;
    $ptid = 0;
    $aid = 0;
    $avid = 0;
    $sid = 0;
    $chid = 0;
    $mediaId = 0;
    $externalId = '';
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO {$prefix}companies (name,status,createtime,updatetime) VALUES (:n,:st,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())"
        );
        $stmt->execute(["n" => "T14Video_" . uniqid(), "st" => "normal"]);
        $cid = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO {$prefix}wallets (company_id,currency,available_balance,frozen_balance,version) VALUES ({$cid},'CNY',100.00,0.00,0)");

        $stmt = $pdo->prepare(
            "INSERT INTO {$prefix}keywords (company_id,keyword,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kw,'normal',0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())"
        );
        $stmt->execute(["cid" => $cid, "kw" => "T14_KW"]);
        $kwid = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            "INSERT INTO {$prefix}questions (company_id,keyword_id,text,text_hash,source,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kwid,:t,:th,'manual','normal',0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())"
        );
        $stmt->execute(["cid" => $cid, "kwid" => $kwid, "t" => "T14_Q", "th" => hash("sha256", "T14_Q", true)]);
        $qid = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            "INSERT INTO {$prefix}prompt_templates (company_id,logical_key,version_no,name,scene,content,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:lk,1,:nm,'article','prompt','normal',0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())"
        );
        $stmt->execute(["cid" => $cid, "lk" => "t14pt", "nm" => "T14_PT"]);
        $ptid = (int) $pdo->lastInsertId();

        $now = time();
        $stmt = $pdo->prepare(
            "INSERT INTO {$prefix}articles (company_id,keyword_id,question_id,title,status,created_by,updated_by,createtime,updatetime) VALUES (:cid,:kwid,:qid,'T14 Article','draft',0,0,:ct,:ut)"
        );
        $stmt->execute(["cid" => $cid, "kwid" => $kwid, "qid" => $qid, "ct" => $now, "ut" => $now]);
        $aid = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            "INSERT INTO {$prefix}article_versions (company_id,article_id,version_no,title_snapshot,content,source,prompt_template_id,created_by,createtime) VALUES (:cid,:aid,1,'T14 Article','T14 Content','manual',:ptid,0,UNIX_TIMESTAMP())"
        );
        $stmt->execute(["cid" => $cid, "aid" => $aid, "ptid" => $ptid]);
        $avid = (int) $pdo->lastInsertId();
        $pdo->exec("UPDATE {$prefix}articles SET current_version_id={$avid} WHERE id={$aid}");

        $sid = (int) ($pdo->query("SELECT id FROM {$prefix}suppliers WHERE code='media_market' LIMIT 1")->fetchColumn() ?: 0);
        if ($sid === 0) {
            $pdo->exec("INSERT INTO {$prefix}suppliers (code,display_name,status,created_at,updated_at) VALUES ('media_market','Media Marketplace','normal',NOW(),NOW())");
            $sid = (int) $pdo->lastInsertId();
        }
        $chid = (int) ($pdo->query("SELECT id FROM {$prefix}channels WHERE code='influencer' LIMIT 1")->fetchColumn() ?: 0);
        if ($chid === 0) {
            $pdo->exec("INSERT INTO {$prefix}channels (code,name,category,status,supplier_confirmed,created_at,updated_at) VALUES ('influencer','Self-Media KOL','influencer','normal',1,NOW(),NOW())");
            $chid = (int) $pdo->lastInsertId();
        } else {
            $pdo->exec("UPDATE {$prefix}channels SET supplier_confirmed=1 WHERE id={$chid}");
        }

        $externalId = 'video-test-' . substr(hash('sha256', uniqid('', true)), 0, 24);
        $stmt = $pdo->prepare(
            "INSERT INTO {$prefix}media_resources (supplier_id,external_id,channel_id,name,cost_price,cost_currency,status,attributes_json,last_synced_at,created_at,updated_at) VALUES (:sid,:eid,:chid,:nm,7.500000,'CNY','active',:attrs,NOW(),NOW(),NOW())"
        );
        $stmt->execute([
            "sid" => $sid,
            "eid" => $externalId,
            "chid" => $chid,
            "nm" => "中原聚焦生活（直发）",
            "attrs" => json_encode([
                "platform" => "抖音",
                "price_unconfirmed" => !$priceConfirmed,
                "price_source" => $priceConfirmed ? "official_api_shortMarketPrice" : "",
                "price_unit" => $priceConfirmed ? "CNY_元" : "",
                "source_catalog" => $catalog,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $mediaId = (int) $pdo->lastInsertId();

        $fn($cid, $aid, $avid, $mediaId, $sid, $chid, $externalId);
    } finally {
        $pdo->exec("DELETE FROM {$prefix}order_status_logs WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}publish_order_items WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}publish_orders WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}ledger_entries WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}wallets WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}media_resources WHERE id={$mediaId}");
        if ($aid > 0) {
            $pdo->exec("UPDATE {$prefix}articles SET current_version_id=NULL WHERE id={$aid}");
            $pdo->exec("DELETE FROM {$prefix}article_versions WHERE article_id={$aid}");
            $pdo->exec("DELETE FROM {$prefix}articles WHERE id={$aid}");
        }
        $pdo->exec("DELETE FROM {$prefix}prompt_templates WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}questions WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}keywords WHERE company_id={$cid}");
        $pdo->exec("DELETE FROM {$prefix}companies WHERE id={$cid}");
    }
}

function createVideoOrder(
    PDO $pdo,
    string $prefix,
    PublishOrderService $orderSvc,
    int $cid,
    int $aid,
    int $avid,
    int $mediaId,
    string $suffix
): array {
    $p = $orderSvc->preview($cid, $aid, $avid, [$mediaId]);
    $ik = "t14video-" . $suffix . "-" . uniqid();
    $o = $orderSvc->create(
        $cid,
        1,
        $aid,
        $avid,
        [$mediaId],
        $ik,
        $p["price_fingerprint"],
        "",
        "https://pan.baidu.com/s/t14-video"
    );
    $orderSvc->queueForSubmit($cid, (int) $o["id"]);
    return $o;
}

function ledgerCount(PDO $pdo, string $prefix, int $cid, string $entryType): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM {$prefix}ledger_entries WHERE company_id=:cid AND entry_type=:et"
    );
    $stmt->execute(["cid" => $cid, "et" => $entryType]);
    return (int) $stmt->fetchColumn();
}

echo "T14 Video Order Flow Tests\n===========================\n";

test("010 video mapper marks shortMarketPrice as confirmed cost", function () {
    $raw = [
        "videoMediaId" => "video-1",
        "accName" => "测试账号",
        "shortMarketPrice" => 7.5,
        "isDelete" => true,
        "mediaExtendAttrList" => [
            ["sysDictData" => ["dictValue" => "sys_video_live_sspt", "dictLabel" => "抖音"]],
        ],
    ];
    $item = \app\common\supplier\MediaMarketFieldMapper::toVideoMediaItem(
        $raw,
        "media_market",
        \app\common\supplier\dto\ChannelCategory::INFLUENCER
    );
    assertTrue($item->costPrice()->amount() === "7.500000", "cost should be 7.500000");
    assertTrue($item->priceUnconfirmed() === false, "price should be confirmed");
    $attrs = $item->attributes()->toArray();
    assertTrue(($attrs["price_source"] ?? "") === "official_api_shortMarketPrice");
    assertTrue(($attrs["price_unit"] ?? "") === "CNY_元");
});

test("020 OrderQuery carries channel code", function () {
    $q = new OrderQuery("video-pop-1", "", "influencer");
    assertTrue($q->channelCode() === "influencer");
    assertTrue($q->externalOrderNo() === "video-pop-1");
    $q2 = new OrderQuery("news-1");
    assertTrue($q2->channelCode() === null);
    $q3 = new OrderQuery("video-pop-1", "", "influencer", "/video");
    assertTrue($q3->catalog() === "/video");
});

test("030 video submit uses official POST payload", function () {
    $captured = null;
    $mock = new MockHandler([
        new Response(200, [], '{"code":200,"data":{"token":"test-token","expires_in":60}}'),
        new Response(200, [], '{"code":200,"mess":"成功","data":[{"videoPopId":"video-pop-1","videoPopOutTradeNo":"0812144350452034","videoPopMediaId":"ee3b8474ca8741a3aa0ac6c3d06e9899","videoPopType":1,"videoPopAuditing":0,"videoPopSaleMoney":7.5,"videoPopMarketMoney":7.5}]}'),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(function (callable $handler) use (&$captured) {
        return function ($request, array $options) use ($handler, &$captured) {
            $captured = $request;
            return $handler($request, $options);
        };
    });
    $http = new Client(["handler" => $stack]);
    $adapter = new \app\common\supplier\MediaMarketAdapter([
        "base_url" => "https://media-market.example.com",
        "username" => "testuser",
        "password" => "testpass",
        "access_code" => "testcode",
        "connect_timeout" => 5,
        "request_timeout" => 30,
        "max_retries" => 1,
    ], $http);
    $result = $adapter->submitOrder(new OrderSubmitRequest(
        "video-submit-0001",
        "influencer",
        ["ee3b8474ca8741a3aa0ac6c3d06e9899"],
        ["project_name" => "测试项目", "content" => "测试内容", "link" => "https://pan.baidu.com/s/t14-video", "put_in_time" => "2026-08-18 10:00:00", "person" => "测试", "tel" => "13800000000", "qq" => ""]
    ));
    assertTrue($result->externalOrderNo() === "0812144350452034");
    assertTrue($result->supplierRequestId() === "video-pop-1");
    assertTrue($result->status() === "submitted");
    assertTrue($captured !== null);
    assertTrue((string) $captured->getUri()->getPath() === "/popularize/video");
    $body = json_decode((string) $captured->getBody(), true);
    assertTrue(($body["videoPopularize"]["videoPopAccName"] ?? "") === "测试项目");
    assertTrue(($body["videoPopularize"]["videoPopTel"] ?? "") === "13800000000");
    assertTrue(($body["videoPopularize"]["videoPopContent"] ?? "") === "https://pan.baidu.com/s/t14-video");
    assertTrue(($body["mediaIdList"] ?? []) === ["ee3b8474ca8741a3aa0ac6c3d06e9899"]);
});

test("031 video submit fails closed without video link", function () {
    $mock = new MockHandler([
        new Response(200, [], '{"code":200,"data":{"token":"test-token","expires_in":60}}'),
        new Response(200, [], '{"code":200,"mess":"成功","data":[{"videoPopId":"video-pop-x","videoPopOutTradeNo":"x","videoPopMediaId":"ee3b8474ca8741a3aa0ac6c3d06e9899","videoPopType":1,"videoPopAuditing":0,"videoPopSaleMoney":7.5}]}'),
    ]);
    $stack = HandlerStack::create($mock);
    $http = new Client(["handler" => $stack]);
    $adapter = new \app\common\supplier\MediaMarketAdapter([
        "base_url" => "https://media-market.example.com",
        "username" => "testuser",
        "password" => "testpass",
        "access_code" => "testcode",
        "connect_timeout" => 5,
        "request_timeout" => 30,
        "max_retries" => 1,
    ], $http);
    $threw = false;
    try {
        $adapter->submitOrder(new OrderSubmitRequest(
            "video-submit-no-link",
            "influencer",
            ["ee3b8474ca8741a3aa0ac6c3d06e9899"],
            ["person" => "测试", "tel" => "13800000000"]
        ));
    } catch (\app\common\supplier\exception\SupplierConfigurationException $e) {
        $threw = strpos($e->getMessage(), "video link") !== false;
    }
    assertTrue($threw, "video submit without link must fail closed before supplier POST");
});

test("031b video local order rejects missing link before wallet freeze", function () use ($pdo, $prefix, $orderSvc) {
    withVideoFixtures($pdo, $prefix, function ($cid, $aid, $avid, $mediaId) use ($pdo, $prefix, $orderSvc) {
        $preview = $orderSvc->preview($cid, $aid, $avid, [$mediaId]);
        $threw = false;
        try {
            $orderSvc->create(
                $cid,
                1,
                $aid,
                $avid,
                [$mediaId],
                "t14-video-no-link-" . uniqid(),
                $preview["price_fingerprint"]
            );
        } catch (\app\common\publishing\PublishException $e) {
            $threw = strpos($e->getMessage(), "视频链接") !== false;
        }
        assertTrue($threw, "video local order without link must be rejected");
        $wallet = $pdo->query("SELECT available_balance,frozen_balance FROM {$prefix}wallets WHERE company_id={$cid}")->fetch();
        assertTrue(bccomp((string) $wallet["available_balance"], "100.00", 2) === 0);
        assertTrue(bccomp((string) $wallet["frozen_balance"], "0.00", 2) === 0);
        assertTrue((int) $pdo->query("SELECT COUNT(*) FROM {$prefix}publish_orders WHERE company_id={$cid}")->fetchColumn() === 0);
    });
});

test("031c failed queue dispatch moves queued order to manual review without changing funds", function () use ($pdo, $prefix, $orderSvc) {
    withVideoFixtures($pdo, $prefix, function ($cid, $aid, $avid, $mediaId) use ($pdo, $prefix, $orderSvc) {
        $preview = $orderSvc->preview($cid, $aid, $avid, [$mediaId]);
        $order = $orderSvc->create(
            $cid,
            1,
            $aid,
            $avid,
            [$mediaId],
            "t14-queue-failure-" . uniqid(),
            $preview["price_fingerprint"],
            "",
            "https://pan.baidu.com/s/t14-video"
        );
        $orderSvc->queueForSubmit($cid, (int) $order["id"]);
        $before = $pdo->query("SELECT available_balance,frozen_balance FROM {$prefix}wallets WHERE company_id={$cid}")->fetch();

        $orderSvc->markSubmissionQueueFailed($cid, (int) $order["id"]);

        $detail = $orderSvc->getDetail($cid, (int) $order["id"], false);
        $after = $pdo->query("SELECT available_balance,frozen_balance FROM {$prefix}wallets WHERE company_id={$cid}")->fetch();
        assertTrue($detail["order"]["status"] === "manual_review", "queue failure must not remain falsely queued");
        assertTrue($detail["items"][0]["status"] === "manual_review", "queue failure must mark each queued item recoverable");
        assertTrue((string) $after["available_balance"] === (string) $before["available_balance"]);
        assertTrue((string) $after["frozen_balance"] === (string) $before["frozen_balance"]);
        assertTrue($detail["items"][0]["supplier_order_no"] === null, "queue failure must not invent a supplier order");
    });
});

test("031d unsubmitted queued recovery cancels and releases exactly once", function () use ($pdo, $prefix, $orderSvc) {
    withVideoFixtures($pdo, $prefix, function ($cid, $aid, $avid, $mediaId) use ($pdo, $prefix, $orderSvc) {
        $preview = $orderSvc->preview($cid, $aid, $avid, [$mediaId]);
        $order = $orderSvc->create(
            $cid,
            1,
            $aid,
            $avid,
            [$mediaId],
            "t14-queued-recovery-" . uniqid(),
            $preview["price_fingerprint"],
            "",
            "https://pan.baidu.com/s/t14-recovery"
        );
        $orderId = (int) $order["id"];
        $orderSvc->queueForSubmit($cid, $orderId);
        $before = $pdo->query("SELECT available_balance,frozen_balance FROM {$prefix}wallets WHERE company_id={$cid}")->fetch();

        $first = $orderSvc->cancelUnsubmittedQueuedOrder($cid, $orderId, 1, "127.0.0.1");
        $afterFirst = $pdo->query("SELECT available_balance,frozen_balance FROM {$prefix}wallets WHERE company_id={$cid}")->fetch();
        $releaseCountFirst = (int) $pdo->query(
            "SELECT COUNT(*) FROM {$prefix}ledger_entries WHERE company_id={$cid} AND biz_type='publish_order' AND biz_id='{$orderId}' AND entry_type='release'"
        )->fetchColumn();

        $second = $orderSvc->cancelUnsubmittedQueuedOrder($cid, $orderId, 1, "127.0.0.1");
        $afterSecond = $pdo->query("SELECT available_balance,frozen_balance FROM {$prefix}wallets WHERE company_id={$cid}")->fetch();
        $releaseCountSecond = (int) $pdo->query(
            "SELECT COUNT(*) FROM {$prefix}ledger_entries WHERE company_id={$cid} AND biz_type='publish_order' AND biz_id='{$orderId}' AND entry_type='release'"
        )->fetchColumn();

        assertTrue($first["status"] === "cancelled" && $second["status"] === "cancelled");
        assertTrue(bccomp((string) $afterFirst["available_balance"], bcadd((string) $before["available_balance"], (string) $order["total_amount"], 2), 2) === 0);
        assertTrue(bccomp((string) $afterFirst["frozen_balance"], bcsub((string) $before["frozen_balance"], (string) $order["total_amount"], 2), 2) === 0);
        assertTrue($afterSecond === $afterFirst, "duplicate recovery must not change wallet twice");
        assertTrue($releaseCountFirst === 1 && $releaseCountSecond === 1, "duplicate recovery must keep exactly one release entry");
        assertTrue((int) $pdo->query(
            "SELECT COUNT(*) FROM {$prefix}ledger_entries WHERE company_id={$cid} AND biz_type='publish_order' AND biz_id='{$orderId}' AND entry_type='capture'"
        )->fetchColumn() === 0, "unsubmitted recovery must never capture funds");
    });
});

test("032 video submit maps creation response without audit status to submitted", function () {
    $mock = new MockHandler([
        new Response(200, [], '{"code":200,"data":{"token":"test-token","expires_in":60}}'),
        new Response(200, [], '{"code":200,"mess":"成功","data":[{"videoPopId":"video-pop-3","videoPopOutTradeNo":"0817211957967793","videoPopMediaId":"ee3b8474ca8741a3aa0ac6c3d06e9899","videoPopType":1,"videoPopSaleMoney":7.5,"videoPopMarketMoney":7.5}]}'),
    ]);
    $stack = HandlerStack::create($mock);
    $http = new Client(["handler" => $stack]);
    $adapter = new \app\common\supplier\MediaMarketAdapter([
        "base_url" => "https://media-market.example.com",
        "username" => "testuser",
        "password" => "testpass",
        "access_code" => "testcode",
        "connect_timeout" => 5,
        "request_timeout" => 30,
        "max_retries" => 1,
    ], $http);
    $result = $adapter->submitOrder(new OrderSubmitRequest(
        "video-submit-no-audit",
        "influencer",
        ["ee3b8474ca8741a3aa0ac6c3d06e9899"],
        ["project_name" => "测试项目", "content" => "测试内容", "link" => "https://example.com/video", "put_in_time" => "2026-08-18 10:00:00", "person" => "测试", "tel" => "13800000000", "qq" => ""]
    ));
    assertTrue($result->externalOrderNo() === "0817211957967793");
    assertTrue($result->supplierRequestId() === "video-pop-3");
    assertTrue($result->status() === "submitted", "creation without audit status should map to submitted");
    assertTrue($result->supplierRawStatus() === "0", "raw status should default to pending audit 0");
});

test("040 video query maps rejected and keeps refuse reason", function () {
    $mock = new MockHandler([
        new Response(200, [], '{"code":200,"data":{"token":"test-token","expires_in":60}}'),
        new Response(200, [], '{"code":200,"mess":"成功","data":{"videoPopId":"video-pop-1","videoPopOutTradeNo":"0812144350452034","videoPopMediaId":"ee3b8474ca8741a3aa0ac6c3d06e9899","videoPopType":1,"videoPopAuditing":3,"videoPopSaleMoney":7.5,"videoPopMarketMoney":7.5,"refuseReason":"内容不合适","createTime":"2026-08-12 14:43:50","updateTime":"2026-08-12 14:43:50","videoMedia":{"accName":"中原聚焦生活（直发）","videoMediaId":"ee3b8474ca8741a3aa0ac6c3d06e9899","shortMarketPrice":7.5,"isLock":false,"isDelete":true}}}'),
    ]);
    $stack = HandlerStack::create($mock);
    $http = new Client(["handler" => $stack]);
    $adapter = new \app\common\supplier\MediaMarketAdapter([
        "base_url" => "https://media-market.example.com",
        "username" => "testuser",
        "password" => "testpass",
        "access_code" => "testcode",
        "connect_timeout" => 5,
        "request_timeout" => 30,
        "max_retries" => 1,
    ], $http);
    $result = $adapter->queryOrder(new OrderQuery("video-pop-1", "", "influencer"));
    assertTrue($result->supplierStatusKnown() === true);
    assertTrue($result->status() === "rejected");
    assertTrue($result->supplierRawStatus() === "3");
    $items = $result->rawPayload()->all()["items"] ?? [];
    assertTrue(($items[0]["refuseReason"] ?? "") === "内容不合适");
    assertTrue($result->publishedUrl() === null);
});

test("050 video query maps published to supplier live address", function () {
    $mock = new MockHandler([
        new Response(200, [], '{"code":200,"data":{"token":"test-token","expires_in":60}}'),
        new Response(200, [], '{"code":200,"mess":"成功","data":{"videoPopId":"video-pop-2","videoPopOutTradeNo":"0812144350452035","videoPopMediaId":"ee3b8474ca8741a3aa0ac6c3d06e9899","videoPopType":1,"videoPopAuditing":2,"videoPopSaleMoney":7.5,"videoPopMarketMoney":7.5,"videoMedia":{"accName":"中原聚焦生活（直发）","videoMediaId":"ee3b8474ca8741a3aa0ac6c3d06e9899","liveAddress":"https://www.douyin.com/user/video-result","shortMarketPrice":7.5,"isLock":false,"isDelete":true}}}'),
    ]);
    $stack = HandlerStack::create($mock);
    $http = new Client(["handler" => $stack]);
    $adapter = new \app\common\supplier\MediaMarketAdapter([
        "base_url" => "https://media-market.example.com",
        "username" => "testuser",
        "password" => "testpass",
        "access_code" => "testcode",
        "connect_timeout" => 5,
        "request_timeout" => 30,
        "max_retries" => 1,
    ], $http);
    $result = $adapter->queryOrder(new OrderQuery("video-pop-2", "", "influencer"));
    assertTrue($result->status() === "published");
    assertTrue($result->publishedUrl() === "https://www.douyin.com/user/video-result");
});

test("060 supplier 5xx fails closed without local funds change", function () use ($pdo, $prefix, $orderSvc) {
    $mock = new MockHandler([
        new Response(200, [], '{"code":200,"data":{"token":"test-token","expires_in":60}}'),
        new Response(500, [], '{"code":500,"mess":"server error"}'),
    ]);
    $stack = HandlerStack::create($mock);
    $http = new Client(["handler" => $stack]);
    $adapter = new \app\common\supplier\MediaMarketAdapter([
        "base_url" => "https://media-market.example.com",
        "username" => "testuser",
        "password" => "testpass",
        "access_code" => "testcode",
        "connect_timeout" => 5,
        "request_timeout" => 30,
        "max_retries" => 1,
    ], $http);
    $threw = false;
    try {
        $adapter->submitOrder(new OrderSubmitRequest(
            "video-submit-5xx",
            "influencer",
            ["ee3b8474ca8741a3aa0ac6c3d06e9899"],
            ["person" => "测试", "tel" => "13800000000", "link" => "https://example.com/video"]
        ));
    } catch (\app\common\supplier\exception\SupplierServerException $e) {
        $threw = true;
    }
    assertTrue($threw, "5xx should raise SupplierServerException");
});

test("070 submit timeout raises SupplierTimeoutException", function () {
    $mock = new MockHandler([
        new Response(200, [], '{"code":200,"data":{"token":"test-token","expires_in":60}}'),
        new ConnectException(
            "timed out",
            new Request("POST", "/popularize/video"),
            null,
            ["errno" => 28]
        ),
    ]);
    $stack = HandlerStack::create($mock);
    $http = new Client(["handler" => $stack]);
    $adapter = new \app\common\supplier\MediaMarketAdapter([
        "base_url" => "https://media-market.example.com",
        "username" => "testuser",
        "password" => "testpass",
        "access_code" => "testcode",
        "connect_timeout" => 5,
        "request_timeout" => 30,
        "max_retries" => 1,
    ], $http);
    $threw = false;
    try {
        $adapter->submitOrder(new OrderSubmitRequest(
            "video-submit-timeout",
            "influencer",
            ["ee3b8474ca8741a3aa0ac6c3d06e9899"],
            ["person" => "测试", "tel" => "13800000000", "link" => "https://example.com/video"]
        ));
    } catch (\app\common\supplier\exception\SupplierTimeoutException $e) {
        $threw = true;
    }
    assertTrue($threw, "timeout should raise SupplierTimeoutException");
});

test("100 video order submit, rejected sync and single release", function () use ($pdo, $prefix, $orderSvc, $submitSvc, $syncSvc) {
    withVideoFixtures($pdo, $prefix, function ($cid, $aid, $avid, $mediaId, $sid, $chid, $externalId) use ($pdo, $prefix, $orderSvc, $submitSvc, $syncSvc) {
        $o = createVideoOrder($pdo, $prefix, $orderSvc, $cid, $aid, $avid, $mediaId, "reject");
        $adapter = new FakeVideoSupplierAdapter();
        $adapter->externalMediaId = $externalId;
        $submitSvc->submitOrder(
            $adapter,
            $cid,
            (int) $o["id"],
            "influencer",
            [$externalId],
            "submit-reject-" . uniqid(),
            "",
            ["project_name" => "测试项目", "content" => "测试内容", "link" => "https://example.com/video", "put_in_time" => "2026-08-18 10:00:00", "person" => "测试", "tel" => "13800000000"]
        );
        $detail = $orderSvc->getDetail($cid, (int) $o["id"], false);
        assertTrue($detail["order"]["status"] === "submitted");
        $item = $detail["items"][0];
        assertTrue($item["status"] === "submitted");
        assertTrue($item["supplier_order_no"] === "0812144350452034");
        $rowStmt = $pdo->prepare("SELECT supplier_submit_request_id, supplier_raw_status FROM {$prefix}publish_order_items WHERE company_id=:cid AND order_id=:oid LIMIT 1");
        $rowStmt->execute(["cid" => $cid, "oid" => (int) $o["id"]]);
        $itemRow = $rowStmt->fetch();
        assertTrue(($itemRow["supplier_submit_request_id"] ?? "") === "video-pop-1");
        assertTrue(($itemRow["supplier_raw_status"] ?? "") === "0");

        $sync = $syncSvc->syncOrder($adapter, $cid, (int) $o["id"]);
        assertTrue($sync["synced"] === 1, "one item should sync");
        $detail2 = $orderSvc->getDetail($cid, (int) $o["id"], false);
        assertTrue($detail2["order"]["status"] === "rejected");
        $billingService = new BillingService($pdo, new PdoAuditLogger($pdo, $prefix), $prefix);
        $wallet = $billingService->getWallet($cid);
        assertTrue(bccomp((string) $wallet["available_balance"], "100.00", 2) === 0);
        assertTrue(bccomp((string) $wallet["frozen_balance"], "0.00", 2) === 0);
        assertTrue(ledgerCount($pdo, $prefix, $cid, "release") === 1, "release must happen exactly once");
        $reasons = array_column($detail2["status_logs"], "reason");
        assertTrue(in_array("Sync: supplier status 3 -> rejected (supplier reason: 内容不合适)", $reasons, true), "refuse reason missing from status log");

        $sync2 = $syncSvc->syncOrder($adapter, $cid, (int) $o["id"]);
        assertTrue($sync2["status"] === "skipped", "repeat sync must skip");
        assertTrue(ledgerCount($pdo, $prefix, $cid, "release") === 1, "repeat sync must not release twice");
        $detail3 = $orderSvc->getDetail($cid, (int) $o["id"], false);
        $orderSvc->transitionItem($cid, (int) $o["id"], (int) $detail3["items"][0]["id"], "rejected", "system", "duplicate attempt");
        assertTrue(ledgerCount($pdo, $prefix, $cid, "release") === 1, "duplicate rejected transition must not release twice");
    });
});

test("110 duplicate submit does not create a second supplier order", function () use ($pdo, $prefix, $orderSvc, $submitSvc) {
    withVideoFixtures($pdo, $prefix, function ($cid, $aid, $avid, $mediaId, $sid, $chid, $externalId) use ($pdo, $prefix, $orderSvc, $submitSvc) {
        $o = createVideoOrder($pdo, $prefix, $orderSvc, $cid, $aid, $avid, $mediaId, "dup");
        $adapter = new FakeVideoSupplierAdapter();
        $submitSvc->submitOrder(
            $adapter,
            $cid,
            (int) $o["id"],
            "influencer",
            [$externalId],
            "submit-dup-" . uniqid(),
            "",
            ["person" => "测试", "tel" => "13800000000", "link" => "https://example.com/video"]
        );
        assertTrue($adapter->submitCalls === 1, "first submit must reach supplier once");
        $secondThrew = false;
        try {
            $submitSvc->submitOrder(
                $adapter,
                $cid,
                (int) $o["id"],
                "influencer",
                [$externalId],
                "submit-dup-2-" . uniqid(),
                "",
                ["person" => "测试", "tel" => "13800000000"]
            );
        } catch (\app\common\publishing\PublishException $e) {
            $secondThrew = true;
        }
        assertTrue($secondThrew, "second submit on submitted order must be rejected locally");
        assertTrue($adapter->submitCalls === 1, "no second supplier POST may happen");
    });
});

test("120 submit-time rejection releases funds once", function () use ($pdo, $prefix, $orderSvc, $submitSvc) {
    withVideoFixtures($pdo, $prefix, function ($cid, $aid, $avid, $mediaId, $sid, $chid, $externalId) use ($pdo, $prefix, $orderSvc, $submitSvc) {
        $o = createVideoOrder($pdo, $prefix, $orderSvc, $cid, $aid, $avid, $mediaId, "rejectnow");
        $adapter = new FakeVideoSupplierAdapter();
        $adapter->submitRejected = true;
        $submitSvc->submitOrder(
            $adapter,
            $cid,
            (int) $o["id"],
            "influencer",
            [$externalId],
            "submit-reject-now-" . uniqid(),
            "",
            ["person" => "测试", "tel" => "13800000000", "link" => "https://example.com/video"]
        );
        $billingService = new BillingService($pdo, new PdoAuditLogger($pdo, $prefix), $prefix);
        $wallet = $billingService->getWallet($cid);
        assertTrue(bccomp((string) $wallet["frozen_balance"], "0.00", 2) === 0);
        assertTrue(ledgerCount($pdo, $prefix, $cid, "release") === 1);
        $detail = $orderSvc->getDetail($cid, (int) $o["id"], false);
        assertTrue($detail["order"]["status"] === "rejected");
        assertTrue($adapter->submitCalls === 1);
    });
});

test("130 video published sync captures once and stores live address", function () use ($pdo, $prefix, $orderSvc, $submitSvc, $syncSvc) {
    withVideoFixtures($pdo, $prefix, function ($cid, $aid, $avid, $mediaId, $sid, $chid, $externalId) use ($pdo, $prefix, $orderSvc, $submitSvc, $syncSvc) {
        $o = createVideoOrder($pdo, $prefix, $orderSvc, $cid, $aid, $avid, $mediaId, "pub");
        $adapter = new FakeVideoSupplierAdapter();
        $adapter->externalMediaId = $externalId;
        $submitSvc->submitOrder(
            $adapter,
            $cid,
            (int) $o["id"],
            "influencer",
            [$externalId],
            "submit-pub-" . uniqid(),
            "",
            ["person" => "测试", "tel" => "13800000000", "link" => "https://example.com/video"]
        );
        $adapter->queryScenario = "published";
        $sync = $syncSvc->syncOrder($adapter, $cid, (int) $o["id"]);
        assertTrue($sync["synced"] === 1, "first sync should move to processing: " . json_encode($sync, JSON_UNESCAPED_UNICODE));
        $detail = $orderSvc->getDetail($cid, (int) $o["id"], false);
        assertTrue($detail["order"]["status"] === "processing", "order status after first sync: " . $detail["order"]["status"]);
        assertTrue(ledgerCount($pdo, $prefix, $cid, "capture") === 0, "no capture before published");

        $sync = $syncSvc->syncOrder($adapter, $cid, (int) $o["id"]);
        assertTrue($sync["synced"] === 1, "sync should update one item: " . json_encode($sync, JSON_UNESCAPED_UNICODE));
        $detail = $orderSvc->getDetail($cid, (int) $o["id"], false);
        assertTrue($detail["order"]["status"] === "published", "order status: " . $detail["order"]["status"]);
        assertTrue($detail["items"][0]["published_url"] === "https://www.douyin.com/user/video-result", "published url: " . var_export($detail["items"][0]["published_url"] ?? null, true));
        assertTrue(ledgerCount($pdo, $prefix, $cid, "capture") === 1, "capture must happen exactly once");

        $sync2 = $syncSvc->syncOrder($adapter, $cid, (int) $o["id"]);
        assertTrue($sync2["status"] === "skipped", "repeat published sync must skip: " . json_encode($sync2, JSON_UNESCAPED_UNICODE));
        assertTrue(ledgerCount($pdo, $prefix, $cid, "capture") === 1, "repeat sync must not capture twice");
        $detail2 = $orderSvc->getDetail($cid, (int) $o["id"], false);
        $orderSvc->transitionItem($cid, (int) $o["id"], (int) $detail2["items"][0]["id"], "published", "system", "duplicate attempt");
        assertTrue(ledgerCount($pdo, $prefix, $cid, "capture") === 1, "duplicate published transition must not capture twice");
    });
});

test("140 influencer submit rejects non-video catalog media before POST", function () use ($pdo, $prefix, $orderSvc) {
    \think\Config::set("geo_supplier", [
        "driver" => "mock",
        "environment" => "testing",
        "mock" => ["scenario" => "default"],
        "media_market" => [],
    ]);
    withVideoFixtures($pdo, $prefix, function ($cid, $aid, $avid, $mediaId, $sid, $chid, $externalId) use ($pdo, $prefix, $orderSvc) {
        $o = createVideoOrder($pdo, $prefix, $orderSvc, $cid, $aid, $avid, $mediaId, "guard");
        $threw = false;
        try {
            PublishSubmitJob::runInline($pdo, ["company_id" => $cid, "order_id" => (int) $o["id"]], $prefix);
        } catch (\RuntimeException $e) {
            $threw = strpos($e->getMessage(), "unconfirmed price") !== false;
        }
        assertTrue($threw, "non-video influencer media must be rejected before supplier POST");
    }, "/media/type/3", false);
});

test("150 video order update sends video link payload", function () {
    $captured = null;
    $mock = new MockHandler([
        new Response(200, [], '{"code":200,"data":{"token":"test-token","expires_in":60}}'),
        new Response(200, [], '{"code":200,"mess":"成功","data":[{"videoPopId":"video-pop-1","videoPopOutTradeNo":"0812144350452034","videoPopMediaId":"ee3b8474ca8741a3aa0ac6c3d06e9899","videoPopType":1,"videoPopAuditing":0,"videoPopSaleMoney":7.5,"videoPopMarketMoney":7.5}]}'),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(function (callable $handler) use (&$captured) {
        return function ($request, array $options) use ($handler, &$captured) {
            $captured = $request;
            return $handler($request, $options);
        };
    });
    $http = new Client(["handler" => $stack]);
    $client = new \app\common\supplier\MediaMarketApiClient([
        "base_url" => "https://media-market.example.com",
        "username" => "testuser",
        "password" => "testpass",
        "access_code" => "testcode",
        "connect_timeout" => 5,
        "request_timeout" => 30,
        "max_retries" => 1,
    ], $http);
    $result = $client->updateVideoOrder([
        "videoPopAccName" => "测试项目",
        "videoPopType" => "1",
        "videoPopId" => "video-pop-1",
        "videoPopPutInTime" => "2026-08-18 10:00:00",
        "videoPopContent" => "https://example.com/video",
        "videoPopPerson" => "测试",
        "videoPopTel" => "13800000000",
        "videoPopQQ" => "",
        "mediaIdList" => ["ee3b8474ca8741a3aa0ac6c3d06e9899"],
    ]);
    assertTrue($captured !== null);
    assertTrue($captured->getMethod() === "PUT");
    assertTrue((string) $captured->getUri()->getPath() === "/popularize/video");
    $body = json_decode((string) $captured->getBody(), true);
    assertTrue(($body["videoPopularize"]["videoPopId"] ?? "") === "video-pop-1");
    assertTrue(($body["videoPopularize"]["videoPopPutInTime"] ?? "") === "2026-08-18 10:00:00");
    assertTrue(($body["videoPopularize"]["videoPopContent"] ?? "") === "https://example.com/video");
    assertTrue(($body["mediaIdList"] ?? []) === ["ee3b8474ca8741a3aa0ac6c3d06e9899"]);
    assertTrue(($result["externalOrderNo"] ?? "") === "0812144350452034");
    assertTrue(($result["supplierRequestId"] ?? "") === "video-pop-1");
});

test("131 supplier jump to published transitions through processing and captures once", function () use ($pdo, $prefix, $orderSvc, $submitSvc, $syncSvc) {
    withVideoFixtures($pdo, $prefix, function ($cid, $aid, $avid, $mediaId, $sid, $chid, $externalId) use ($pdo, $prefix, $orderSvc, $submitSvc, $syncSvc) {
        $o = createVideoOrder($pdo, $prefix, $orderSvc, $cid, $aid, $avid, $mediaId, "jump");
        $adapter = new FakeVideoSupplierAdapter();
        $adapter->externalMediaId = $externalId;
        $adapter->queryScenario = "published";
        $adapter->publishedQueriesBeforePublished = 0;
        $submitSvc->submitOrder(
            $adapter,
            $cid,
            (int) $o["id"],
            "influencer",
            [$externalId],
            "submit-jump-" . uniqid(),
            "",
            ["person" => "测试", "tel" => "13800000000", "link" => "https://example.com/video"]
        );
        $sync = $syncSvc->syncOrder($adapter, $cid, (int) $o["id"]);
        assertTrue($sync["synced"] === 1, "jump sync should count one item: " . json_encode($sync, JSON_UNESCAPED_UNICODE));
        $detail = $orderSvc->getDetail($cid, (int) $o["id"], false);
        assertTrue($detail["order"]["status"] === "published", "order status after jump: " . $detail["order"]["status"]);
        assertTrue($detail["items"][0]["published_url"] === "https://www.douyin.com/user/video-result", "published url stored");
        assertTrue(ledgerCount($pdo, $prefix, $cid, "capture") === 1, "capture exactly once");
    });
});

echo "\nT14 Video Order Flow: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);

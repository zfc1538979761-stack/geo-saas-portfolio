<?php

declare(strict_types=1);

define("ROOT_PATH", dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define("APP_PATH", ROOT_PATH . "application" . DIRECTORY_SEPARATOR);
require ROOT_PATH . "thinkphp" . DIRECTORY_SEPARATOR . "base.php";
\think\Loader::addNamespace("app", APP_PATH);

use app\common\supplier\MediaMarketAdapter;
use app\common\supplier\dto\ChannelCategory;
use app\common\publishing\PublishSyncService;

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "PASS: {$name}\n";
        $pass++;
    } catch (\Throwable $e) {
        echo "FAIL: {$name} - " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assertTrue(bool $condition, string $message = ""): void {
    if (!$condition) {
        throw new \RuntimeException($message !== "" ? $message : "assertion failed");
    }
}

function safetyAdapter(): MediaMarketAdapter {
    return new MediaMarketAdapter([
        "base_url" => "https://media-market.example.com",
        "username" => "testuser",
        "password" => "testpass",
        "access_code" => "testcode",
        "connect_timeout" => 5,
        "request_timeout" => 30,
        "max_retries" => 1,
    ]);
}

function resolveChannel(MediaMarketAdapter $adapter, array $raw): string {
    $ref = new ReflectionMethod(MediaMarketAdapter::class, "resolveChannel");
    $ref->setAccessible(true);
    return (string) $ref->invoke($adapter, $raw);
}

function mapSupplierStatus(MediaMarketAdapter $adapter, string $status): string {
    $ref = new ReflectionMethod(MediaMarketAdapter::class, "mapSupplierStatus");
    $ref->setAccessible(true);
    return (string) $ref->invoke($adapter, $status);
}

echo "T14 Channel and Status Safety Tests\n====================================\n";

$adapter = safetyAdapter();
$officialLabel = "\u{5b98}\u{65b9}\u{5a92}\u{4f53}";
$commercialLabel = "\u{5546}\u{4e1a}\u{5a92}\u{4f53}";

test("mediaType=1 with valid identity maps to official_media", function () use ($adapter, $officialLabel) {
    $raw = [
        "mediaType" => 1,
        "mediaId" => "news-1",
        "mediaName" => "测试媒体",
        "mediaExtendAttrList" => [
            ["sysDictData" => ["dictValue" => "sys_news_extend_zmkf", "dictLabel" => $officialLabel]],
        ],
    ];
    assertTrue(resolveChannel($adapter, $raw) === ChannelCategory::OFFICIAL_MEDIA);
});

test("commercial media from /media/type/1 maps to official_media channel", function () use ($adapter, $commercialLabel) {
    $raw = [
        "mediaType" => 1,
        "mediaId" => "cnblogs-1",
        "mediaName" => "博客园",
        "mediaExtendAttrList" => [
            ["sysDictData" => ["dictValue" => "sys_news_extend_zmkf", "dictLabel" => $commercialLabel]],
        ],
    ];
    assertTrue(resolveChannel($adapter, $raw) === ChannelCategory::OFFICIAL_MEDIA);
});

test("mediaType=1 without identity stays unconfirmed", function () use ($adapter) {
    $raw = ["mediaType" => 1, "mediaExtendAttrList" => []];
    assertTrue(resolveChannel($adapter, $raw) === "unconfirmed");
});

test("display placement mediaType=2 is unconfirmed", function () use ($adapter) {
    $raw = ["mediaType" => 2, "mediaExtendAttrList" => []];
    assertTrue(resolveChannel($adapter, $raw) === "unconfirmed");
});

test("personal media with platform dict maps to influencer", function () use ($adapter) {
    $raw = [
        "mediaType" => 3,
        "mediaExtendAttrList" => [
            ["sysDictData" => ["dictValue" => "sys_personal_medium_sspt", "dictLabel" => "zhihu"]],
        ],
        "fansNum" => 1000,
    ];
    assertTrue(resolveChannel($adapter, $raw) === ChannelCategory::INFLUENCER);
});

test("personal media with fans/read fields maps to influencer", function () use ($adapter) {
    $raw = ["mediaType" => 3, "fansNum" => 500, "readNum" => 120];
    assertTrue(resolveChannel($adapter, $raw) === ChannelCategory::INFLUENCER);
});

test("personal media without personal fields is unconfirmed", function () use ($adapter) {
    $raw = ["mediaType" => 3];
    assertTrue(resolveChannel($adapter, $raw) === "unconfirmed");
});

test("media without mediaType is unconfirmed", function () use ($adapter) {
    $raw = ["mediaName" => "unknown", "mediaExtendAttrList" => []];
    assertTrue(resolveChannel($adapter, $raw) === "unconfirmed");
});

test("resolveChannel never returns b2b without API evidence", function () use ($adapter, $officialLabel) {
    $fixtures = [
        ["mediaType" => 1, "mediaId" => "news-1", "mediaName" => "测试媒体", "mediaExtendAttrList" => [["sysDictData" => ["dictValue" => "sys_news_extend_zmkf", "dictLabel" => $officialLabel]]]],
        ["mediaType" => 3, "mediaExtendAttrList" => [["sysDictData" => ["dictValue" => "sys_personal_medium_sspt", "dictLabel" => "weibo"]]]],
        ["mediaType" => 2],
        [],
    ];
    foreach ($fixtures as $raw) {
        assertTrue(resolveChannel($adapter, $raw) !== ChannelCategory::B2B, "b2b must stay unopened");
    }
});

test("mapSupplierStatus uses official API doc mapping", function () use ($adapter) {
    assertTrue(mapSupplierStatus($adapter, "0") === "submitted");
    assertTrue(mapSupplierStatus($adapter, "1") === "processing");
    assertTrue(mapSupplierStatus($adapter, "2") === "published");
    assertTrue(mapSupplierStatus($adapter, "3") === "rejected");
    assertTrue(mapSupplierStatus($adapter, "4") === "manual_review");
    assertTrue(mapSupplierStatus($adapter, "unknown") === "manual_review");
    assertTrue(mapSupplierStatus($adapter, "") === "manual_review");
});

test("PublishSyncService mapStatus matches official API doc", function () {
    $refClass = new ReflectionClass(PublishSyncService::class);
    $svc = $refClass->newInstanceWithoutConstructor();
    $ref = $refClass->getMethod("mapStatus");
    $ref->setAccessible(true);
    assertTrue($ref->invoke($svc, "0") === "submitted");
    assertTrue($ref->invoke($svc, "1") === "processing");
    assertTrue($ref->invoke($svc, "2") === "published");
    assertTrue($ref->invoke($svc, "3") === "rejected");
    assertTrue($ref->invoke($svc, "4") === "manual_review");
    assertTrue($ref->invoke($svc, "unknown") === "manual_review");
});

echo "\nT14 Safety: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);

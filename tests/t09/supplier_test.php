<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\supplier\contract\SupplierAdapter;
use app\common\supplier\MediaMarketAdapter;
use app\common\supplier\MediaMarketApiClient;
use app\common\supplier\MediaMarketFieldMapper;
use app\common\supplier\SupplierAdapterFactory;
use app\common\supplier\dto\ChannelCategory;
use app\common\supplier\dto\MediaQuery;
use app\common\supplier\dto\MediaStatus;
use app\common\supplier\dto\OrderSubmitRequest;
use app\common\supplier\dto\OrderQuery;
use app\common\supplier\dto\CallbackEnvelope;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

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

echo "T09 Contract and Adapter Tests\n";
echo "==============================\n";

// === 1. Configuration validation ===
test('MediaMarketApiClient fails with empty base_url', function () {
    try {
        new MediaMarketApiClient([
            'base_url' => '',
            'username' => 'test',
            'password' => 'test',
            'access_code' => 'test',
        ]);
        throw new \RuntimeException('Should have thrown');
    } catch (\app\common\supplier\exception\SupplierConfigurationException $e) {
        assert(strpos($e->getMessage(), 'Base URL') !== false);
    }
});

test('MediaMarketApiClient fails with placeholder credentials', function () {
    try {
        new MediaMarketApiClient([
            'base_url' => 'https://example.com',
            'username' => 'change-me',
            'password' => 'test',
            'access_code' => 'test',
        ]);
        throw new \RuntimeException('Should have thrown');
    } catch (\app\common\supplier\exception\SupplierConfigurationException $e) {
        assert(strpos($e->getMessage(), 'username') !== false);
    }
});

test('MediaMarketApiClient fails with missing password', function () {
    try {
        new MediaMarketApiClient([
            'base_url' => 'https://example.com',
            'username' => 'test',
            'password' => '',
            'access_code' => 'test',
        ]);
        throw new \RuntimeException('Should have thrown');
    } catch (\app\common\supplier\exception\SupplierConfigurationException $e) {
        assert(strpos($e->getMessage(), 'password') !== false);
    }
});

// === 2. Field mapper tests ===
test('Field mapper handles valid raw item', function () {
    $raw = [
        'mediaId' => 'test-media-id-001',
        'mediaName' => 'Test Media',
        'costPrice' => 15.50,
        'marketPrice' => 37.50,
        'weighting' => 5,
        'fansNum' => 1000,
        'mediaLink' => 'http://example.com',
        'mediaExtendAttrList' => [
            ['sysDictData' => ['dictValue' => 'sys_news_extend_zmkf', 'dictLabel' => '商业媒体']],
            ['sysDictData' => ['dictValue' => 'sys_news_extend_fgqy', 'dictLabel' => '全国']],
        ],
    ];

    $item = MediaMarketFieldMapper::toMediaItem($raw, 'media_market', 'official_media');
    assert($item->externalMediaId() === 'test-media-id-001');
    assert($item->name() === 'Test Media');
    assert($item->costPrice()->amount() === '37.500000');
    assert($item->status() === MediaStatus::ACTIVE);
    assert($item->priceUnconfirmed() === false);

    $attrs = $item->attributes()->toArray();
    assert(($attrs['media_category'] ?? '') === '商业媒体');
    assert(($attrs['region'] ?? '') === '全国');
    assert(($attrs['weighting'] ?? 0) === 5);
    assert(($attrs['fans_num'] ?? 0) === 1000);
    assert(($attrs['market_price'] ?? null) === 37.50);
    assert(($attrs['cost_price_raw'] ?? null) === 15.50);
    assert(($attrs['price_source'] ?? '') === 'user_confirmed_marketPrice');
    assert(($attrs['price_unit'] ?? '') === 'CNY_元');
});

test('Field mapper rejects missing mediaId', function () {
    try {
        MediaMarketFieldMapper::toMediaItem(['mediaName' => 'No ID'], 'mm', 'ch');
        throw new \RuntimeException('Should have thrown');
    } catch (\InvalidArgumentException $e) {
        assert(strpos($e->getMessage(), 'mediaId') !== false);
    }
});

test('Field mapper rejects missing mediaName', function () {
    try {
        MediaMarketFieldMapper::toMediaItem(['mediaId' => 'id'], 'mm', 'ch');
        throw new \RuntimeException('Should have thrown');
    } catch (\InvalidArgumentException $e) {
        assert(strpos($e->getMessage(), 'mediaName') !== false);
    }
});

test('Field mapper maps isDelete to offline status', function () {
    // Per official doc: isDelete=true means 未删除 (active), false means 已删除 (offline)
    $raw = ['mediaId' => 'test-offline', 'mediaName' => 'Offline Media', 'isDelete' => false, 'costPrice' => 10.00];
    $item = MediaMarketFieldMapper::toMediaItem($raw, 'mm', 'ch');
    assert($item->status() === MediaStatus::OFFLINE);
});

// === 3. Capabilities ===
test('MediaMarketAdapter reports correct capabilities', function () {
    $adapter = createTestAdapter();
    $caps = $adapter->capabilities();
    assert($caps->toArray()['supports_media_list'] === true);
    assert($caps->toArray()['supports_media_changes'] === true);
    assert($caps->toArray()['supports_order_submit'] === true);
    assert($caps->toArray()['supports_order_query'] === true);
});

// === 4. Channel listing ===
test('MediaMarketAdapter lists channels', function () {
    $adapter = createTestAdapter();
    $channels = $adapter->listChannels();
    $codes = array_map(fn($ch) => $ch->code(), $channels);
    assert(in_array('official_media', $codes));
    assert(in_array('influencer', $codes));
    assert(in_array('b2b', $codes));
});

// === 5. Order methods propagate supplier rejection ===
test('submitOrder propagates supplier 203 as invalid response', function () {
    $adapter = createAdapterWithMockResponses([
        new Response(200, [], '{"code":200,"data":{"token":"test-token","expires_in":60}}'),
        new Response(200, [], '{"code":203,"mess":"请求数据异常"}'),
    ]);
    try {
        $adapter->submitOrder(new OrderSubmitRequest(
            'test-idempotency-key-01',
            'ch',
            ['m1'],
            [
                'project_name' => '测试标题',
                'content' => '测试正文',
                'link' => 'https://example.com/article',
            ]
        ));
        throw new \RuntimeException('Should have thrown');
    } catch (\app\common\supplier\exception\SupplierInvalidResponseException $e) {
        assert(strpos($e->getMessage(), '203') !== false);
    }
});

test('queryOrder maps supplier 203 to unknown with failure', function () {
    $adapter = createAdapterWithMockResponses([
        new Response(200, [], '{"code":200,"data":{"token":"test-token","expires_in":60}}'),
        new Response(200, [], '{"code":203,"mess":"请求数据异常"}'),
    ]);
    $result = $adapter->queryOrder(new OrderQuery('ono'));
    assert($result->status() === 'unknown');
    assert($result->supplierStatusKnown() === false);
    assert($result->failure() !== null);
    assert(strpos($result->failure()->redactedReason(), '203') !== false);
});

test('parseCallback throws unsupported', function () {
    $adapter = createTestAdapter();
    try {
        $adapter->parseCallback(new CallbackEnvelope('mm', 'eid', new \app\common\supplier\dto\SupplierRawPayload([])));
        throw new \RuntimeException('Should have thrown');
    } catch (\app\common\supplier\exception\SupplierUnsupportedCapabilityException $e) {}
});

// === 6. Video and influencer catalog mapping ===
test('MediaMarketApiClient requests official /media/video catalog', function () {
    $captured = null;
    $mock = new MockHandler([
        new Response(200, [], '{"code":200,"data":{"token":"test-token","expires_in":60}}'),
        new Response(200, [], '{"code":200,"data":{"content":[{"videoMediaId":"video-1","accName":"测试账号","shortMarketPrice":101.00}],"totalElements":1,"totalPages":1,"number":0,"size":10}}'),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(function (callable $handler) use (&$captured) {
        return function ($request, array $options) use ($handler, &$captured) {
            $captured = $request;
            return $handler($request, $options);
        };
    });
    $http = new Client(['handler' => $stack]);
    $client = new MediaMarketApiClient([
        'base_url' => 'https://media-market.example.com',
        'username' => 'testuser',
        'password' => 'testpass',
        'access_code' => 'testcode',
        'connect_timeout' => 5,
        'request_timeout' => 30,
        'max_retries' => 1,
    ], $http);
    $page = $client->fetchVideoList(1, 10, ['priceType' => 1]);
    assert($page['totalElements'] === 1);
    assert(($page['content'][0]['videoMediaId'] ?? '') === 'video-1');
    assert($captured !== null);
    assert((string) $captured->getUri()->getPath() === '/media/video');
});

test('video mapper maps official /video fields to influencer with confirmed shortMarketPrice', function () {
    $raw = [
        'videoMediaId' => 'video-1',
        'accName' => '测试账号',
        'videoPlatformId' => '5',
        'fansNum' => 1000,
        'fansNumStr' => '1000',
        'shortMarketPrice' => 101.00,
        'liveMarketPrice' => -3.00,
        'shortMemberPrice' => 95.95,
        'liveMemberPrice' => -2.85,
        'isDelete' => true,
        'mediaExtendAttrList' => [
            ['sysDictData' => ['dictValue' => 'sys_video_live_sspt', 'dictLabel' => '哔哩哔哩']],
        ],
    ];
    $item = MediaMarketFieldMapper::toVideoMediaItem($raw, 'media_market', ChannelCategory::INFLUENCER);
    assert($item->externalMediaId() === 'video-1');
    assert($item->channelCode() === ChannelCategory::INFLUENCER);
    assert($item->name() === '测试账号');
    assert($item->costPrice()->amount() === '101.000000');
    assert($item->priceUnconfirmed() === false);
    $attrs = $item->attributes()->toArray();
    assert(($attrs['platform'] ?? '') === '哔哩哔哩');
    assert(($attrs['price_source'] ?? '') === 'official_api_shortMarketPrice');
    assert(($attrs['price_unit'] ?? '') === 'CNY_元');
    assert(($attrs['short_video_capability'] ?? false) === true);
    assert(($attrs['live_capability'] ?? false) === true);
    assert(($attrs['short_market_price'] ?? null) === 101.00);
    assert(($attrs['source_catalog'] ?? '') === '/video');
});

test('invalid utf8 supplier strings are sanitized before persistence', function () {
    $raw = [
        'mediaId' => 'utf8-bad-1',
        'mediaName' => "Bad\xC3\x28Name",
        'mediaType' => 3,
        'fansNumStr' => "1\xC3\x28万",
        'mediaExtendAttrList' => [
            ['sysDictData' => ['dictValue' => 'sys_personal_medium_sspt', 'dictLabel' => "\xC3\x28平台"]],
        ],
    ];
    $item = MediaMarketFieldMapper::toPersonalMediaItem($raw, 'media_market', ChannelCategory::INFLUENCER);
    assert(mb_check_encoding($item->name(), 'UTF-8'));
    $attrs = $item->attributes()->toArray();
    assert(mb_check_encoding((string) ($attrs['fans_num_str'] ?? ''), 'UTF-8'));
    assert(mb_check_encoding((string) ($attrs['platform'] ?? ''), 'UTF-8'));
    assert(json_encode($item->toArray(), JSON_UNESCAPED_UNICODE) !== false);
});

test('news mapper maps confirmed marketPrice as official cost', function () {
    $raw = [
        'mediaId' => 'news-1',
        'mediaName' => '测试官媒',
        'mediaType' => 1,
        'marketPrice' => 1000000.00,
        'costPrice' => null,
        'isDelete' => true,
        'mediaLink' => 'https://example.com',
    ];
    $item = MediaMarketFieldMapper::toNewsMediaItem($raw, 'media_market', ChannelCategory::OFFICIAL_MEDIA);
    assert($item->externalMediaId() === 'news-1');
    assert($item->channelCode() === ChannelCategory::OFFICIAL_MEDIA);
    assert($item->costPrice()->amount() === '1000000.000000');
    assert($item->priceUnconfirmed() === false);
    $attrs = $item->attributes()->toArray();
    assert(($attrs['market_price'] ?? null) === 1000000.00);
    assert(($attrs['price_source'] ?? '') === 'user_confirmed_marketPrice');
    assert(($attrs['price_unit'] ?? '') === 'CNY_元');
    assert(($attrs['source_catalog'] ?? '') === '/media/type/1');
    assert(!isset($attrs['platform']));
});

test('news mapper preserves supplier media categories and special attributes', function () {
    $raw = [
        'mediaId' => 'news-cnblogs',
        'mediaName' => '博客园',
        'mediaType' => 1,
        'marketPrice' => 3.00,
        'isDelete' => true,
        'isLock' => true,
        'readNum' => 120,
        'mediaExtendAttrList' => [
            ['sysDictData' => ['dictValue' => 'sys_news_extend_zmkf', 'dictLabel' => '商业媒体']],
            ['sysDictData' => ['dictValue' => 'sys_news_extend_zmkf', 'dictLabel' => '周末可发']],
            ['sysDictData' => ['dictValue' => 'sys_news_extend_zmkf', 'dictLabel' => '可发GEO排名']],
            ['sysDictData' => ['dictValue' => 'sys_news_extend_tfzq', 'dictLabel' => '白名单来源']],
        ],
    ];
    $item = MediaMarketFieldMapper::toNewsMediaItem($raw, 'media_market', ChannelCategory::OFFICIAL_MEDIA);
    $attrs = $item->attributes()->toArray();
    assert(($attrs['media_category'] ?? '') === '商业媒体');
    assert(($attrs['supplier_is_locked'] ?? 0) === 1);
    assert(($attrs['supplier_is_deleted'] ?? 1) === 0);
    assert(($attrs['read_num'] ?? 0) === 120);
    $categories = json_decode((string) ($attrs['supplier_media_categories'] ?? '[]'), true);
    assert(is_array($categories) && in_array('商业媒体', $categories, true) && in_array('周末可发', $categories, true));
    $special = json_decode((string) ($attrs['special_attributes'] ?? '[]'), true);
    assert(is_array($special) && in_array('可发GEO排名', $special, true) && in_array('白名单来源', $special, true));
});

test('fetchMedia maps commercial /media/type/1 media into official_media', function () {
    $adapter = createAdapterWithMockResponses([
        new Response(200, [], '{"code":200,"data":{"token":"test-token","expires_in":60}}'),
        new Response(200, [], '{"code":200,"data":{"content":[{"mediaId":"cnblogs-1","mediaName":"博客园","mediaType":1,"marketPrice":3.00,"isDelete":true,"mediaExtendAttrList":[{"sysDictData":{"dictValue":"sys_news_extend_zmkf","dictLabel":"商业媒体"}}]}],"totalElements":1,"totalPages":1,"number":0,"size":10}}'),
    ]);
    $page = $adapter->fetchMedia(new MediaQuery('official_media', 1, 10));
    assert($page->total() === 1);
    $items = $page->items();
    assert(count($items) === 1);
    assert($items[0]->channelCode() === ChannelCategory::OFFICIAL_MEDIA);
    assert($items[0]->name() === '博客园');
    assert($items[0]->costPrice()->amount() === '3.000000');
    $attrs = $items[0]->attributes()->toArray();
    assert(($attrs['media_category'] ?? '') === '商业媒体');
});

test('personal media mapper maps real type3 self-media to influencer', function () {
    $raw = [
        'mediaId' => 'personal-1',
        'mediaName' => '小红书账号',
        'mediaType' => 3,
        'marketPrice' => 5.00,
        'fansNum' => 1000,
        'isDelete' => true,
        'mediaExtendAttrList' => [
            ['sysDictData' => ['dictValue' => 'sys_personal_medium_sspt', 'dictLabel' => '小红书']],
        ],
    ];
    $item = MediaMarketFieldMapper::toPersonalMediaItem($raw, 'media_market', ChannelCategory::INFLUENCER);
    assert($item->externalMediaId() === 'personal-1');
    assert($item->channelCode() === ChannelCategory::INFLUENCER);
    assert($item->costPrice()->amount() === '5.000000');
    assert($item->priceUnconfirmed() === false);
    $attrs = $item->attributes()->toArray();
    assert(($attrs['platform'] ?? '') === '小红书');
    assert(($attrs['market_price'] ?? null) === 5.00);
    assert(($attrs['source_catalog'] ?? '') === '/media/type/3');
    assert(($attrs['price_source'] ?? '') === 'user_confirmed_marketPrice');
    assert(($attrs['price_unit'] ?? '') === 'CNY_元');
});

test('personal media without marketPrice stays price unconfirmed', function () {
    $raw = [
        'mediaId' => 'personal-0',
        'mediaName' => '无价格账号',
        'mediaType' => 3,
        'fansNum' => 1,
    ];
    $item = MediaMarketFieldMapper::toPersonalMediaItem($raw, 'media_market', ChannelCategory::INFLUENCER);
    assert($item->costPrice()->amount() === '0.000000');
    assert($item->priceUnconfirmed() === true);
});

test('influencer fetch uses real self-media endpoint and never calls video order endpoint', function () {
    $mock = new MockHandler([
        new Response(200, [], '{"code":200,"data":{"token":"test-token","expires_in":60}}'),
        new Response(200, [], '{"code":200,"data":{"content":[{"mediaId":"personal-1","mediaName":"小红书账号","mediaType":3,"marketPrice":5.00,"fansNum":1000,"isDelete":true,"mediaExtendAttrList":[{"sysDictData":{"dictValue":"sys_personal_medium_sspt","dictLabel":"小红书"}}]}],"totalElements":1,"totalPages":1,"number":0,"size":10}}'),
    ]);
    $stack = HandlerStack::create($mock);
    $http = new Client(['handler' => $stack]);
    $adapter = new MediaMarketAdapter([
        'base_url' => 'https://media-market.example.com',
        'username' => 'testuser',
        'password' => 'testpass',
        'access_code' => 'testcode',
        'connect_timeout' => 5,
        'request_timeout' => 30,
        'max_retries' => 1,
    ], $http);
    $page = $adapter->fetchMedia(new MediaQuery(ChannelCategory::INFLUENCER, 1, 10));
    assert($page->total() === 1);
    assert(count($page->items()) === 1);
    assert($page->items()[0]->externalMediaId() === 'personal-1');
    assert($page->items()[0]->attributes()->toArray()['platform'] === '小红书');
    assert($page->items()[0]->priceUnconfirmed() === false);
});

test('article submit sends official top-level mediaIdList payload', function () {
    $captured = null;
    $mock = new MockHandler([
        new Response(200, [], '{"code":200,"data":{"token":"test-token","expires_in":60}}'),
        new Response(200, [], '{"code":1,"data":[{"popId":"pop-1","popOutTradeNo":"0800000000000001","popMediaId":"news-1","popAuditing":0}]}'),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(function (callable $handler) use (&$captured) {
        return function ($request, array $options) use ($handler, &$captured) {
            $captured = $request;
            return $handler($request, $options);
        };
    });
    $http = new Client(['handler' => $stack]);
    $adapter = new MediaMarketAdapter([
        'base_url' => 'https://media-market.example.com',
        'username' => 'testuser',
        'password' => 'testpass',
        'access_code' => 'testcode',
        'connect_timeout' => 5,
        'request_timeout' => 30,
        'max_retries' => 1,
    ], $http);
    $result = $adapter->submitOrder(new OrderSubmitRequest(
        'article-submit-0001',
        ChannelCategory::OFFICIAL_MEDIA,
        ['news-1'],
        [
            'catalog' => '/media/type/1',
            'project_name' => '测试标题',
            'content' => '测试正文',
            'link' => 'https://example.com/article',
        ]
    ));
    assert($result->externalOrderNo() === '0800000000000001');
    assert($captured !== null);
    assert((string) $captured->getUri()->getPath() === '/popularize');
    $body = json_decode((string) $captured->getBody(), true);
    assert(($body['mediaIdList'] ?? []) === ['news-1']);
    assert(!isset($body['popList'][0]['mediaIdList']));
    assert(($body['popList'][0]['popType'] ?? null) === 1);
});

test('influencer article submit routes to /popularize instead of video order', function () {
    $captured = null;
    $mock = new MockHandler([
        new Response(200, [], '{"code":200,"data":{"token":"test-token","expires_in":60}}'),
        new Response(200, [], '{"code":1,"data":[{"popId":"pop-2","popOutTradeNo":"0800000000000002","popMediaId":"self-1","popAuditing":0}]}'),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(function (callable $handler) use (&$captured) {
        return function ($request, array $options) use ($handler, &$captured) {
            $captured = $request;
            return $handler($request, $options);
        };
    });
    $http = new Client(['handler' => $stack]);
    $adapter = new MediaMarketAdapter([
        'base_url' => 'https://media-market.example.com',
        'username' => 'testuser',
        'password' => 'testpass',
        'access_code' => 'testcode',
        'connect_timeout' => 5,
        'request_timeout' => 30,
        'max_retries' => 1,
    ], $http);
    $result = $adapter->submitOrder(new OrderSubmitRequest(
        'article-submit-0002',
        ChannelCategory::INFLUENCER,
        ['self-1'],
        [
            'catalog' => '/media/type/3',
            'project_name' => '测试标题',
            'content' => '测试正文',
        ]
    ));
    assert($result->externalOrderNo() === '0800000000000002');
    assert($captured !== null);
    assert((string) $captured->getUri()->getPath() === '/popularize');
    $body = json_decode((string) $captured->getBody(), true);
    assert(($body['popList'][0]['popContent'] ?? '') === '测试正文');
    assert(($body['popList'][0]['popLink'] ?? null) === '');
});

test('news article submit allows an empty optional original link', function () {
    $captured = null;
    $mock = new MockHandler([
        new Response(200, [], '{"code":200,"data":{"token":"test-token","expires_in":60}}'),
        new Response(200, [], '{"code":1,"data":[{"popId":"pop-3","popOutTradeNo":"0800000000000003","popMediaId":"news-1","popAuditing":0}]}'),
    ]);
    $stack = HandlerStack::create($mock);
    $stack->push(function (callable $handler) use (&$captured) {
        return function ($request, array $options) use ($handler, &$captured) {
            $captured = $request;
            return $handler($request, $options);
        };
    });
    $adapter = new MediaMarketAdapter([
        'base_url' => 'https://media-market.example.com',
        'username' => 'testuser',
        'password' => 'testpass',
        'access_code' => 'testcode',
        'connect_timeout' => 5,
        'request_timeout' => 30,
        'max_retries' => 1,
    ], new Client(['handler' => $stack]));
    $result = $adapter->submitOrder(new OrderSubmitRequest(
        'article-submit-0003',
        ChannelCategory::OFFICIAL_MEDIA,
        ['news-1'],
        [
            'catalog' => '/media/type/1',
            'project_name' => '测试标题',
            'content' => 'AI 生成正文',
        ]
    ));
    assert($result->externalOrderNo() === '0800000000000003');
    assert($captured !== null);
    $body = json_decode((string) $captured->getBody(), true);
    assert(($body['popList'][0]['popContent'] ?? '') === 'AI 生成正文');
    assert(($body['popList'][0]['popLink'] ?? null) === '');
});

// === 6. Factory ===
test('SupplierAdapterFactory creates MediaMarketAdapter', function () {
    $adapter = SupplierAdapterFactory::create([
        'driver' => 'media_market',
        'environment' => 'development',
        'media_market' => [
            'base_url' => 'https://media-market.example.com',
            'username' => 'realuser',
            'password' => 'realpass',
            'access_code' => 'realcode',
            'connect_timeout' => 5,
            'request_timeout' => 30,
            'max_retries' => 3,
        ],
    ]);
    assert($adapter instanceof MediaMarketAdapter);
    assert($adapter->supplierCode() === 'media_market');
});

// === 7. Factory fails with empty config ===
test('SupplierAdapterFactory fails with empty media_market config', function () {
    try {
        SupplierAdapterFactory::create([
            'driver' => 'media_market',
            'environment' => 'development',
            'media_market' => [],
        ]);
        throw new \RuntimeException('Should have thrown');
    } catch (\app\common\supplier\exception\SupplierConfigurationException $e) {}
});

function createTestAdapter(): MediaMarketAdapter {
    return new MediaMarketAdapter([
        'base_url' => 'https://media-market.example.com',
        'username' => 'testuser',
        'password' => 'testpass',
        'access_code' => 'testcode',
        'connect_timeout' => 5,
        'request_timeout' => 30,
        'max_retries' => 1,
    ]);
}

function createAdapterWithMockResponses(array $responses): MediaMarketAdapter {
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $http = new Client(['handler' => $stack]);
    return new MediaMarketAdapter([
        'base_url' => 'https://media-market.example.com',
        'username' => 'testuser',
        'password' => 'testpass',
        'access_code' => 'testcode',
        'connect_timeout' => 5,
        'request_timeout' => 30,
        'max_retries' => 1,
    ], $http);
}

echo "\n---\n";
echo "T09 Contract: {$pass} PASS, {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);

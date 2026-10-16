<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\supplier\MockSupplierAdapter;
use app\common\supplier\SupplierAdapterFactory;
use app\common\supplier\SupplierStatusMapper;
use app\common\supplier\contract\SupplierAdapter;
use app\common\supplier\dto\CallbackEnvelope;
use app\common\supplier\dto\Channel;
use app\common\supplier\dto\CostPrice;
use app\common\supplier\dto\MediaAttributes;
use app\common\supplier\dto\MediaChangeQuery;
use app\common\supplier\dto\MediaItem;
use app\common\supplier\dto\MediaPage;
use app\common\supplier\dto\MediaQuery;
use app\common\supplier\dto\OrderQuery;
use app\common\supplier\dto\OrderStatus;
use app\common\supplier\dto\OrderSubmitItemResult;
use app\common\supplier\dto\OrderSubmitRequest;
use app\common\supplier\dto\OrderSubmitResult;
use app\common\supplier\dto\OrderResult;
use app\common\supplier\dto\ParsedCallback;
use app\common\supplier\dto\SupplierCapabilities;
use app\common\supplier\dto\SupplierFailure;
use app\common\supplier\dto\SupplierRawPayload;
use app\common\supplier\exception\SupplierConfigurationException;
use app\common\supplier\exception\SupplierDuplicateCallbackException;
use app\common\supplier\exception\SupplierDuplicateOrderException;
use app\common\supplier\exception\SupplierException;
use app\common\supplier\exception\SupplierInvalidResponseException;
use app\common\supplier\exception\SupplierTimeoutException;
use app\common\supplier\exception\SupplierUnknownStatusException;
use app\common\supplier\exception\SupplierUnsupportedCapabilityException;

$passed = 0;
$failed = 0;

function t08ContractAssert(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
        return;
    }
    $failed++;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
}

function t08ContractReject(callable $operation, string $category, bool $retryable, string $message): void
{
    try {
        $operation();
        t08ContractAssert(false, $message);
    } catch (SupplierException $exception) {
        t08ContractAssert(
            $exception->category() === $category && $exception->retryable() === $retryable,
            $message
        );
    } catch (Throwable $exception) {
        t08ContractAssert(false, $message . ' wrong exception=' . get_class($exception));
    }
}

$required = [
    SupplierAdapter::class,
    SupplierCapabilities::class,
    Channel::class,
    CostPrice::class,
    MediaAttributes::class,
    MediaItem::class,
    MediaQuery::class,
    MediaPage::class,
    MediaChangeQuery::class,
    OrderStatus::class,
    OrderSubmitRequest::class,
    OrderSubmitItemResult::class,
    OrderSubmitResult::class,
    OrderQuery::class,
    OrderResult::class,
    ParsedCallback::class,
    SupplierFailure::class,
    SupplierRawPayload::class,
    CallbackEnvelope::class,
    MockSupplierAdapter::class,
    SupplierAdapterFactory::class,
    SupplierStatusMapper::class,
    SupplierConfigurationException::class,
    SupplierTimeoutException::class,
    SupplierDuplicateOrderException::class,
    SupplierUnknownStatusException::class,
    SupplierUnsupportedCapabilityException::class,
    SupplierInvalidResponseException::class,
    SupplierDuplicateCallbackException::class,
];
foreach ($required as $class) {
    t08ContractAssert(
        class_exists($class) || interface_exists($class),
        'T08 supplier class exists: ' . $class
    );
}

if (count(array_filter($required, static function (string $class): bool {
    return class_exists($class) || interface_exists($class);
})) === count($required)) {
    $default = new MockSupplierAdapter('testing', ['scenario' => 'default']);
    t08ContractAssert($default instanceof SupplierAdapter, 'Mock implements the unified supplier interface');
    t08ContractAssert($default->supplierCode() === 'mock', 'Mock identifies itself as mock only');

    $emptyCapabilities = new SupplierCapabilities();
    t08ContractAssert(
        !$emptyCapabilities->supportsOrderSubmit()
        && !$emptyCapabilities->supportsRefund()
        && !$emptyCapabilities->supportsCallback(),
        'capability flags fail closed by default'
    );

    $capabilities = $default->capabilities();
    t08ContractAssert(
        $capabilities->supportsChannels()
        && $capabilities->supportsMediaList()
        && $capabilities->supportsMediaChanges()
        && $capabilities->supportsOrderSubmit()
        && $capabilities->supportsOrderQuery()
        && $capabilities->supportsCallback()
        && $capabilities->supportsCancel()
        && $capabilities->supportsRefund(),
        'Mock advertises the full dev/testing capability set'
    );

    $channels = $default->listChannels();
    t08ContractAssert(count($channels) === 3, 'Mock returns the three internal channel classifications');
    $channelJson = json_encode(array_map(static fn(Channel $channel): array => $channel->toArray(), $channels), JSON_UNESCAPED_UNICODE);
    t08ContractAssert(
        strpos((string) $channelJson, 'official_media') !== false
        && strpos((string) $channelJson, 'influencer') !== false
        && strpos((string) $channelJson, 'b2b') !== false,
        'channel codes cover official media, influencer, and B2B'
    );
    t08ContractAssert(
        strpos((string) $channelJson, '"supplierConfirmed":false') !== false,
        'Mock does not claim real supplier confirmation for any channel'
    );

    $media = $default->fetchMedia(new MediaQuery('official_media', 1, 10));
    t08ContractAssert($media instanceof MediaPage && $media->total() === 1, 'Mock media list is a unified page');
    $item = $media->items()[0];
    t08ContractAssert($item instanceof MediaItem, 'Mock media items use the unified MediaItem DTO');
    t08ContractAssert(
        $item->externalMediaId() !== '' && $item->name() !== '' && $item->status() === 'active',
        'Mock media exposes external id, name, and unified status'
    );
    t08ContractAssert(
        $item->costPrice() instanceof CostPrice && $item->costPrice()->amount() === '100.000000',
        'Mock media exposes an exact decimal cost price'
    );
    t08ContractAssert(
        $item->attributes() instanceof MediaAttributes && $item->attributes()->all() !== [],
        'Mock media exposes controlled attributes'
    );

    $changes = $default->queryMediaChanges(new MediaChangeQuery('official_media', 'mock-token-1'));
    t08ContractAssert(count($changes->items()) === 3 && $changes->nextToken() !== '', 'Mock media changes are deterministic and paged');

    $submit = new OrderSubmitRequest('idem-key-0001', 'official_media', ['mock-media-official']);
    $submitted = $default->submitOrder($submit);
    t08ContractAssert($submitted instanceof OrderSubmitResult, 'Mock submit returns the unified order result');
    t08ContractAssert(
        $submitted->externalOrderNo() !== null
        && $submitted->status() === OrderStatus::QUEUED
        && $submitted->idempotencyKey() === 'idem-key-0001',
        'Mock submit returns external order number, unified status, and idempotency key'
    );
    t08ContractAssert(
        !$submitted->isPartial() && count($submitted->successfulItemIds()) === 1,
        'Mock submit success has no partial failure'
    );

    $queried = $default->queryOrder(new OrderQuery((string) $submitted->externalOrderNo()));
    t08ContractAssert($queried instanceof OrderResult, 'Mock query returns the unified order result');
    t08ContractAssert(
        $queried->status() === OrderStatus::PUBLISHED
        && $queried->supplierStatusKnown()
        && $queried->publishedUrl() !== '',
        'Mock query success maps a known raw status and returns a published URL'
    );

    $rejected = new MockSupplierAdapter('testing', ['scenario' => 'reject']);
    $rejectedResult = $rejected->queryOrder(new OrderQuery('MM-MOCK-1001'));
    t08ContractAssert(
        $rejectedResult->status() === OrderStatus::REJECTED
        && $rejectedResult->supplierRawStatus() === 'rejected',
        'Mock reject scenario returns a unified rejected status'
    );

    $timeout = new MockSupplierAdapter('testing', ['scenario' => 'timeout']);
    t08ContractReject(
        static function () use ($timeout, $submit): void { $timeout->submitOrder($submit); },
        'timeout',
        true,
        'Mock timeout is a retryable typed supplier error'
    );

    $duplicate = new MockSupplierAdapter('testing', ['scenario' => 'duplicate_order']);
    t08ContractReject(
        static function () use ($duplicate, $submit): void { $duplicate->submitOrder($submit); },
        'duplicate_external_order',
        false,
        'Mock duplicate external order is a typed non-retryable supplier error'
    );

    $unknown = new MockSupplierAdapter('testing', ['scenario' => 'unknown_status']);
    $unknownResult = $unknown->queryOrder(new OrderQuery('MM-MOCK-9999'));
    t08ContractAssert(
        $unknownResult->status() === OrderStatus::MANUAL_REVIEW
        && !$unknownResult->supplierStatusKnown()
        && $unknownResult->supplierRawStatus() === 'mystery-state',
        'Mock unknown status maps to manual review without losing the raw status'
    );
    t08ContractReject(
        static function (): void { SupplierStatusMapper::map('mystery-state'); },
        'unknown_supplier_status',
        false,
        'unknown supplier status is a distinct typed error category'
    );

    $partial = new MockSupplierAdapter('testing', ['scenario' => 'partial_failure']);
    $partialResult = $partial->submitOrder(new OrderSubmitRequest('idem-partial-01', 'official_media', ['mock-media-official', 'mock-media-b2b']));
    t08ContractAssert(
        $partialResult->isPartial()
        && count($partialResult->successfulItemIds()) === 1
        && count($partialResult->failedItemIds()) === 1
        && $partialResult->failedItemResults()[0]->failure() instanceof SupplierFailure,
        'Mock partial failure exposes typed per-item success and failure results'
    );

    $refundSupported = new MockSupplierAdapter('testing', ['scenario' => 'refund_supported']);
    $refundBlocked = new MockSupplierAdapter('testing', ['scenario' => 'refund_unsupported']);
    t08ContractAssert($refundSupported->capabilities()->supportsRefund(), 'Mock advertises refund support when enabled');
    t08ContractAssert(!$refundBlocked->capabilities()->supportsRefund(), 'Mock advertises refund unsupported when disabled');

    $envelope = new CallbackEnvelope('mock', 'evt-mock-0001', new SupplierRawPayload([
        'event_id' => 'evt-mock-0001',
        'order_no' => 'MM-MOCK-1001',
        'status' => 'published',
    ]));
    $parsed = $default->parseCallback($envelope);
    t08ContractAssert(
        $parsed instanceof ParsedCallback
        && $parsed->eventId() === 'evt-mock-0001'
        && $parsed->status() === OrderStatus::PUBLISHED
        && !$parsed->isDuplicate(),
        'Mock callback verification parses a unified event'
    );

    $invalidCallback = new MockSupplierAdapter('testing', ['scenario' => 'callback_invalid']);
    t08ContractReject(
        static function () use ($invalidCallback, $envelope): void { $invalidCallback->parseCallback($envelope); },
        'invalid_response',
        false,
        'Mock callback verification failure is typed as invalid response'
    );

    $duplicateCallback = new MockSupplierAdapter('testing', ['scenario' => 'callback_duplicate']);
    t08ContractReject(
        static function () use ($duplicateCallback, $envelope): void { $duplicateCallback->parseCallback($envelope); },
        'duplicate_callback_event',
        false,
        'Mock duplicate callback event is a distinct typed error'
    );

    $first = $default->fetchMedia(new MediaQuery('influencer', 1, 10));
    $second = $default->fetchMedia(new MediaQuery('influencer', 1, 10));
    t08ContractAssert(
        json_encode($first->toArray()) === json_encode($second->toArray()),
        'Mock scenarios are repeatable without randomness'
    );

    t08ContractReject(
        static function (): void {
            SupplierAdapterFactory::create(['driver' => 'mock', 'environment' => 'production']);
        },
        'configuration_missing',
        false,
        'production cannot select Mock supplier'
    );
    t08ContractReject(
        static function (): void {
            new MockSupplierAdapter('production');
        },
        'configuration_missing',
        false,
        'Mock constructor refuses production'
    );
    t08ContractReject(
        static function (): void {
            SupplierAdapterFactory::create(['driver' => 'media_market', 'environment' => 'production']);
        },
        'configuration_missing',
        false,
        'real MediaMarket adapter implemented in T09 fails closed with missing config'
    );
    t08ContractReject(
        static function (): void {
            SupplierAdapterFactory::create(['driver' => '', 'environment' => 'production']);
        },
        'configuration_missing',
        false,
        'missing supplier configuration never falls back to Mock'
    );

    $factoryMock = SupplierAdapterFactory::create([
        'driver' => 'mock',
        'environment' => 'testing',
        'mock' => ['scenario' => 'reject'],
    ]);
    t08ContractAssert($factoryMock instanceof MockSupplierAdapter, 'factory exposes Mock only in allowed environments');

    $raw = new SupplierRawPayload([
        'status' => 'published',
        'api_key' => 'secret-value-123456',
        'nested' => ['token' => 'another-secret-123456'],
    ]);
    $redacted = json_encode($raw->toArray(), JSON_UNESCAPED_UNICODE);
    t08ContractAssert(
        strpos((string) $redacted, 'secret-value-123456') === false
        && strpos((string) $redacted, 'another-secret-123456') === false,
        'raw supplier payload redacts secrets before leaving the controlled DTO'
    );
    $failure = new SupplierFailure('provider_server_error', 'supplier said password=private-secret-value', true);
    t08ContractAssert(
        strpos($failure->redactedReason(), 'private-secret-value') === false,
        'supplier failure reasons are redacted'
    );

    $forbiddenPrivateFields = [
        'sysNewsExtend', 'sys_video_live', 'popularize', 'resouce', 'popId',
        'videoPopId', 'shoppingCartId', 'wordToHtml', 'ueditor', 'media/type/1',
    ];
    foreach ([
        $default->fetchMedia(new MediaQuery('official_media', 1, 10))->toArray(),
        $submitted->toArray(),
        $queried->toArray(),
        $parsed->toArray(),
    ] as $dtoArray) {
        $encoded = json_encode($dtoArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($forbiddenPrivateFields as $privateField) {
            t08ContractAssert(
                strpos((string) $encoded, $privateField) === false,
                'unified DTO excludes supplier private field: ' . $privateField
            );
        }
    }

    try {
        new CostPrice('12.3456789', 'CNY');
        t08ContractAssert(false, 'over-precision cost price is rejected');
    } catch (InvalidArgumentException $expected) {
        t08ContractAssert(true, 'over-precision cost price is rejected');
    }
    try {
        new OrderSubmitRequest('short', 'official_media', ['mock-media-official']);
        t08ContractAssert(false, 'short idempotency key is rejected');
    } catch (InvalidArgumentException $expected) {
        t08ContractAssert(true, 'short idempotency key is rejected');
    }
    try {
        new OrderQuery('');
        t08ContractAssert(false, 'empty order query is rejected');
    } catch (InvalidArgumentException $expected) {
        t08ContractAssert(true, 'empty order query is rejected');
    }
}

fwrite(STDOUT, 'RESULT: T08 unified supplier contract, Mock scenarios, and production fail-closed contracts passed=' . $passed . ' failed=' . $failed . PHP_EOL);
exit($failed === 0 ? 0 : 1);

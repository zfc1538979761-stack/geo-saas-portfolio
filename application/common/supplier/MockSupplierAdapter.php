<?php

declare(strict_types=1);

namespace app\common\supplier;

use app\common\supplier\contract\SupplierAdapter;
use app\common\supplier\dto\CallbackEnvelope;
use app\common\supplier\dto\CallbackEventType;
use app\common\supplier\dto\Channel;
use app\common\supplier\dto\ChannelCategory;
use app\common\supplier\dto\CostPrice;
use app\common\supplier\dto\MediaAttributes;
use app\common\supplier\dto\MediaChangeItem;
use app\common\supplier\dto\MediaChangePage;
use app\common\supplier\dto\MediaChangeQuery;
use app\common\supplier\dto\MediaChangeType;
use app\common\supplier\dto\MediaItem;
use app\common\supplier\dto\MediaPage;
use app\common\supplier\dto\MediaQuery;
use app\common\supplier\dto\MediaStatus;
use app\common\supplier\dto\OrderQuery;
use app\common\supplier\dto\OrderResult;
use app\common\supplier\dto\OrderStatus;
use app\common\supplier\dto\OrderSubmitItemResult;
use app\common\supplier\dto\OrderSubmitRequest;
use app\common\supplier\dto\OrderSubmitResult;
use app\common\supplier\dto\ParsedCallback;
use app\common\supplier\dto\SupplierCapabilities;
use app\common\supplier\dto\SupplierFailure;
use app\common\supplier\dto\SupplierRawPayload;
use app\common\supplier\exception\SupplierConfigurationException;
use app\common\supplier\exception\SupplierDuplicateCallbackException;
use app\common\supplier\exception\SupplierDuplicateOrderException;
use app\common\supplier\exception\SupplierInvalidResponseException;
use app\common\supplier\exception\SupplierTimeoutException;
use app\common\supplier\exception\SupplierUnknownStatusException;

final class MockSupplierAdapter implements SupplierAdapter
{
    private const SCENARIOS = [
        'default',
        'submit_success',
        'query_success',
        'reject',
        'timeout',
        'duplicate_order',
        'unknown_status',
        'partial_failure',
        'refund_supported',
        'refund_unsupported',
        'callback_ok',
        'callback_invalid',
        'callback_duplicate',
    ];

    private string $environment;
    private string $scenario;

    public function __construct(string $environment, array $config = [])
    {
        $environment = strtolower(trim($environment));
        if (!in_array($environment, ['development', 'testing'], true)) {
            throw new SupplierConfigurationException('Mock Supplier Adapter is forbidden outside development/testing.');
        }
        $scenario = strtolower(trim((string) ($config['scenario'] ?? 'default')));
        if (!in_array($scenario, self::SCENARIOS, true)) {
            throw new SupplierConfigurationException('Unsupported Mock supplier scenario.');
        }
        $this->environment = $environment;
        $this->scenario = $scenario;
    }

    public function supplierCode(): string
    {
        return 'mock';
    }

    public function capabilities(): SupplierCapabilities
    {
        return new SupplierCapabilities([
            'supports_channels' => true,
            'supports_media_list' => true,
            'supports_media_changes' => true,
            'supports_order_submit' => true,
            'supports_order_query' => true,
            'supports_callback' => true,
            'supports_cancel' => true,
            'supports_refund' => $this->scenario !== 'refund_unsupported',
        ]);
    }

    public function listChannels(): array
    {
        return [
            new Channel('official_media', 'Official Media', ChannelCategory::OFFICIAL_MEDIA, 'normal', false),
            new Channel('influencer', 'Self-Media KOL', ChannelCategory::INFLUENCER, 'normal', false),
            new Channel('b2b', 'B2B', ChannelCategory::B2B, 'normal', false),
        ];
    }

    public function fetchMedia(MediaQuery $query): MediaPage
    {
        $all = [
            $this->fixtureMedia(ChannelCategory::OFFICIAL_MEDIA, 'mock-media-official'),
            $this->fixtureMedia(ChannelCategory::INFLUENCER, 'mock-media-influencer'),
            $this->fixtureMedia(ChannelCategory::B2B, 'mock-media-b2b'),
        ];
        $matched = array_values(array_filter(
            $all,
            static fn(MediaItem $item): bool => $item->channelCode() === $query->channelCode()
        ));
        $offset = ($query->page() - 1) * $query->perPage();
        return new MediaPage(
            $this->supplierCode(),
            array_slice($matched, $offset, $query->perPage()),
            count($matched),
            $query->page(),
            $query->perPage()
        );
    }

    public function queryMediaChanges(MediaChangeQuery $query): MediaChangePage
    {
        $item = $this->fixtureMedia(ChannelCategory::OFFICIAL_MEDIA, 'mock-media-official');
        $changes = [];
        foreach ([
            MediaChangeType::PRICE_CHANGED => 'mock-media-official',
            MediaChangeType::NEW => 'mock-media-new',
            MediaChangeType::OFFLINE => 'mock-media-offline',
        ] as $type => $externalId) {
            if ($query->channelCode() === ChannelCategory::OFFICIAL_MEDIA) {
                $changes[] = new MediaChangeItem(
                    $query->channelCode(),
                    $externalId,
                    $type,
                    $type === MediaChangeType::PRICE_CHANGED ? $item : null
                );
            }
        }
        return new MediaChangePage(
            $this->supplierCode(),
            $changes,
            $query->sinceToken() ?? 'mock-change-token-0',
            $changes === [] ? null : 'mock-change-token-next'
        );
    }

    public function submitOrder(OrderSubmitRequest $request): OrderSubmitResult
    {
        if ($this->scenario === 'timeout') {
            throw new SupplierTimeoutException('Mock supplier request timed out.');
        }
        if ($this->scenario === 'duplicate_order') {
            throw new SupplierDuplicateOrderException('Mock supplier rejected a duplicate external order.');
        }
        if ($this->scenario === 'partial_failure') {
            $itemResults = [];
            foreach ($request->externalMediaIds() as $index => $externalMediaId) {
                if ($index === 0) {
                    $itemResults[] = new OrderSubmitItemResult($externalMediaId, OrderStatus::QUEUED);
                    continue;
                }
                $itemResults[] = new OrderSubmitItemResult(
                    $externalMediaId,
                    OrderStatus::REJECTED,
                    new SupplierFailure('partial_failure', 'Mock item submission failed.', false)
                );
            }
            return new OrderSubmitResult(
                $request->idempotencyKey(),
                'MM-MOCK-1001',
                OrderStatus::MANUAL_REVIEW,
                'partial',
                null,
                new SupplierFailure('partial_failure', 'Mock order has both successful and failed items.', false),
                $itemResults
            );
        }

        $externalOrderNo = 'MM-MOCK-' . substr(hash('sha256', $request->idempotencyKey()), 0, 12);
        $itemResults = [];
        foreach ($request->externalMediaIds() as $externalMediaId) {
            $itemResults[] = new OrderSubmitItemResult($externalMediaId, OrderStatus::QUEUED);
        }
        return new OrderSubmitResult(
            $request->idempotencyKey(),
            $externalOrderNo,
            OrderStatus::QUEUED,
            'queued',
            null,
            null,
            $itemResults
        );
    }

    public function queryOrder(OrderQuery $query): OrderResult
    {
        $externalOrderNo = (string) ($query->externalOrderNo() ?? 'MM-MOCK-1001');
        $mediaId=['/media/type/1'=>'mock-media-official','/media/type/3'=>'mock-media-influencer','/video'=>'mock-media-video'][$query->catalog()??'']??'mock-media-official';
        if ($this->scenario === 'reject') {
            return new OrderResult(
                $externalOrderNo,
                OrderStatus::REJECTED,
                'rejected',
                true,
                null,
                null,
                $query->idempotencyKey(),
                new SupplierRawPayload(['items'=>[['mediaId'=>$mediaId,'status'=>'3','refuseReason'=>'Portfolio simulated rejection']]])
            );
        }
        if ($this->scenario === 'unknown_status') {
            return new OrderResult(
                $externalOrderNo,
                OrderStatus::MANUAL_REVIEW,
                'mystery-state',
                false,
                null,
                new SupplierFailure('unknown_supplier_status', 'Mock supplier status is not mapped.', false),
                $query->idempotencyKey(),
                new SupplierRawPayload(['status' => 'mystery-state'])
            );
        }
        $items = [[
            'mediaId' => $mediaId,
            'publishedUrl' => 'http://127.0.0.1:8877/index/portfolio/published?order=' . $externalOrderNo,
            'status' => '2',
        ]];
        return new OrderResult(
            $externalOrderNo,
            OrderStatus::PUBLISHED,
            'published',
            true,
            'http://127.0.0.1:8877/index/portfolio/published?order=' . $externalOrderNo,
            null,
            $query->idempotencyKey(),
            new SupplierRawPayload(['items' => $items])
        );
    }

    public function parseCallback(CallbackEnvelope $envelope): ParsedCallback
    {
        if ($this->scenario === 'callback_invalid') {
            throw new SupplierInvalidResponseException('Mock callback verification failed.');
        }
        if ($this->scenario === 'callback_duplicate') {
            throw new SupplierDuplicateCallbackException('Mock callback event was already processed.', $envelope->eventId());
        }
        if ($this->scenario === 'unknown_status') {
            throw new SupplierUnknownStatusException('Mock callback contains an unknown supplier status.');
        }
        return new ParsedCallback(
            $envelope->eventId(),
            CallbackEventType::ORDER_STATUS_CHANGED,
            'MM-MOCK-1001',
            OrderStatus::PUBLISHED,
            'published',
            'http://127.0.0.1:8877/index/portfolio/published?order=MM-MOCK-1001',
            null,
            false,
            $envelope->rawPayload()
        );
    }

    private function fixtureMedia(string $channelCode, string $externalMediaId): MediaItem
    {
        return new MediaItem(
            $this->supplierCode(),
            $channelCode,
            $externalMediaId,
            'Mock media ' . $externalMediaId,
            MediaStatus::ACTIVE,
            new CostPrice('100.000000', 'CNY'),
            new MediaAttributes([
                'platform' => 'Mock platform',
                'region' => 'CN',
                'tag' => 'mock',
            ])
        );
    }
}

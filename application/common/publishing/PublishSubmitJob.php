<?php

declare(strict_types=1);

namespace app\common\publishing;

use app\common\job\AbstractQueueJob;
use app\common\billing\BillingService;
use app\common\supplier\SupplierAdapterFactory;
use app\common\tenant\PdoAuditLogger;
use PDO;

final class PublishSubmitJob extends AbstractQueueJob
{
    /**
     * Run the job inline (without Redis/worker).
     */
    public static function runInline(PDO $pdo, array $payload, string $prefix): void
    {
        $companyId = (int) ($payload['company_id'] ?? 0);
        $orderId = (int) ($payload['order_id'] ?? 0);

        if ($companyId <= 0 || $orderId <= 0) {
            throw new \RuntimeException('PublishSubmitJob: invalid payload.');
        }

        $supplierConfig = self::loadSupplierConfig();
        $adapter = SupplierAdapterFactory::create($supplierConfig);

        $audit = new PdoAuditLogger($pdo, $prefix);
        $billing = new BillingService($pdo, $audit, $prefix);
        $orderService = new PublishOrderService($pdo, $billing, $prefix);
        $submitService = new PublishSubmitService($pdo, $orderService, $billing, $prefix);

        $order = $orderService->getDetail($companyId, $orderId);
        if ($order['order']['status'] !== 'queued') {
            throw new \RuntimeException('PublishSubmitJob: order is not queued for submission.');
        }

        $submitContext = self::loadSubmitContext($pdo, $prefix, $companyId, $orderId, $supplierConfig);

        // Collect external media IDs from items
        $items = $pdo->prepare(
            'SELECT i.*, m.external_id, m.attributes_json FROM ' . self::q($prefix . 'publish_order_items') . ' i'
            . ' LEFT JOIN ' . self::q($prefix . 'media_resources') . ' m ON i.media_id = m.id'
            . ' WHERE i.company_id = :cid AND i.order_id = :oid'
        );
        $items->execute(['cid' => $companyId, 'oid' => $orderId]);
        $itemRows = $items->fetchAll(PDO::FETCH_ASSOC);

        $externalMediaIds = [];
        $channelCode = '';
        foreach ($itemRows as $row) {
            $extId = $row['external_id'] ?? '';
            if ($extId !== '') {
                $externalMediaIds[] = $extId;
            }
            if ($channelCode === '' && !empty($row['channel_id'])) {
                // Resolve channel code
                $channelStmt = $pdo->prepare('SELECT code FROM ' . self::q($prefix . 'channels') . ' WHERE id = :id LIMIT 1');
                $channelStmt->execute(['id' => (int) $row['channel_id']]);
                $channelCode = $channelStmt->fetchColumn() ?: '';
            }
        }

        if ($externalMediaIds === []) {
            throw new \RuntimeException('PublishSubmitJob: no external media IDs found for order.');
        }

        $catalog = '';
        if ($channelCode === 'influencer') {
            $catalog = self::resolveOrderCatalog($itemRows);
        }
        $submitLink = trim((string) ($submitContext['link'] ?? ''));
        if ($submitLink === '' || preg_match('/^https?:\/\//i', $submitLink) !== 1) {
            if ($catalog === '/video') {
                throw new \RuntimeException(
                    'PublishSubmitJob: video order requires a video link (http/https).'
                );
            }
        }
        $submitContext['catalog'] = $catalog;

        $idempotencyKey = 'submit-' . $orderId . '-' . substr(md5(implode(',', $externalMediaIds)), 0, 16);

        $submitService->submitOrder(
            $adapter,
            $companyId,
            $orderId,
            $channelCode ?: 'official_media',
            $externalMediaIds,
            $idempotencyKey,
            '',
            $submitContext
        );
    }

    protected function handle(array $payload): void
    {
        // For now, submit synchronously
        $pdo = self::getPdo();
        $prefix = self::loadPrefix();
        self::runInline($pdo, $payload, $prefix);
    }

    private static function getPdo(): PDO
    {
        return PublishRuntimeConfig::requirePdo();
    }

    private static function loadPrefix(): string
    {
        return PublishRuntimeConfig::prefix();
    }

    private static function resolveOrderCatalog(array $itemRows): string
    {
        $catalog = '';
        foreach ($itemRows as $row) {
            $attributes = json_decode((string) ($row['attributes_json'] ?? '{}'), true);
            if (!is_array($attributes)) {
                $attributes = [];
            }
            $externalMediaId = trim((string) ($row['external_id'] ?? ''));
            $sourceCatalog = trim((string) ($attributes['source_catalog'] ?? ''));
            $priceUnconfirmed = (bool) ($attributes['price_unconfirmed'] ?? true);
            if ($priceUnconfirmed) {
                throw new \RuntimeException(
                    'PublishSubmitJob: influencer media ' . $externalMediaId
                    . ' has unconfirmed price; refusing influencer order submission.'
                );
            }
            if (!in_array($sourceCatalog, ['/video', '/media/type/3'], true)) {
                throw new \RuntimeException(
                    'PublishSubmitJob: influencer media ' . $externalMediaId
                    . ' has unsupported catalog ' . $sourceCatalog . '; refusing influencer order submission.'
                );
            }
            if ($catalog === '') {
                $catalog = $sourceCatalog;
                continue;
            }
            if ($catalog !== $sourceCatalog) {
                throw new \RuntimeException(
                    'PublishSubmitJob: cannot mix video and article media in one influencer order.'
                );
            }
        }
        if ($catalog === '') {
            throw new \RuntimeException(
                'PublishSubmitJob: influencer order has no supported media catalog.'
            );
        }
        return $catalog;
    }

    private static function loadSupplierConfig(): array
    {
        return PublishRuntimeConfig::supplier();
    }

    private static function loadSubmitContext(
        PDO $pdo,
        string $prefix,
        int $companyId,
        int $orderId,
        array $supplierConfig
    ): array {
        $stmt = $pdo->prepare(
            'SELECT o.`order_no`, o.`original_link`, v.`title_snapshot`, v.`content`'
            . ' FROM ' . self::q($prefix . 'publish_orders') . ' o'
            . ' JOIN ' . self::q($prefix . 'article_versions') . ' v'
            . ' ON v.`id` = o.`article_version_id` AND v.`article_id` = o.`article_id`'
            . ' WHERE o.`company_id` = :cid AND o.`id` = :oid LIMIT 1'
        );
        $stmt->execute(['cid' => $companyId, 'oid' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [];
        }

        $mediaMarket = (array) ($supplierConfig['media_market'] ?? []);
        $putInTime = trim((string) ($mediaMarket['video_order_put_in_time'] ?? ''));

        return [
            'project_name' => (string) ($row['title_snapshot'] ?? ''),
            'content' => mb_substr((string) ($row['content'] ?? ''), 0, 2000),
            'link' => trim((string) ($row['original_link'] ?? '')),
            'put_in_time' => $putInTime !== '' ? $putInTime : date('Y-m-d H:i:s', strtotime('+1 day')),
            'person' => trim((string) ($mediaMarket['video_order_contact_person'] ?? '')),
            'tel' => trim((string) ($mediaMarket['video_order_contact_tel'] ?? '')),
            'qq' => trim((string) ($mediaMarket['video_order_contact_qq'] ?? '')),
        ];
    }

    private static function q(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) {
            throw new \RuntimeException('SQL identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
}

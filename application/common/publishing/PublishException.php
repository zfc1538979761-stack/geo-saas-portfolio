<?php

declare(strict_types=1);

namespace app\common\publishing;

use RuntimeException;

final class PublishException extends RuntimeException
{
    public static function orderNotFound(int $companyId, int $orderId): self
    {
        return new self("Order {$orderId} not found for company {$companyId}.", 1500);
    }

    public static function orderNotBelongToCompany(int $orderId, int $companyId): self
    {
        return new self("Order {$orderId} does not belong to company {$companyId}.", 1501);
    }

    public static function invalidStateTransition(string $from, string $to): self
    {
        return new self("Invalid state transition from {$from} to {$to}.", 1510);
    }

    public static function duplicateStatusEvent(string $eventKey): self
    {
        return new self("Duplicate status event: {$eventKey}.", 1511);
    }

    public static function idempotencyConflict(string $key): self
    {
        return new self("Idempotency conflict for key: {$key}.", 1520);
    }

    public static function priceChanged(string $currentFingerprint, string $expected): self
    {
        return new self('PRICE_CHANGED', 1521);
    }

    public static function mediaNotAvailable(int $mediaId): self
    {
        return new self("Media {$mediaId} is not available.", 1522);
    }

    public static function articleNotBelongToCompany(int $articleId, int $companyId): self
    {
        return new self("Article {$articleId} does not belong to company {$companyId}.", 1523);
    }

    public static function articleVersionNotBelongToArticle(int $versionId, int $articleId): self
    {
        return new self("Article version {$versionId} does not belong to article {$articleId}.", 1524);
    }

    public static function cancelNotAllowed(string $status): self
    {
        return new self("Cannot cancel order in status {$status}.", 1530);
    }

    public static function itemNotInCancellableState(int $itemId, string $status): self
    {
        return new self("Order item {$itemId} in status {$status} cannot be cancelled.", 1531);
    }

    public static function mediaDuplicateInOrder(int $orderId, int $mediaId): self
    {
        return new self("Media {$mediaId} already exists in order {$orderId}.", 1540);
    }

    public static function emptyMediaList(): self
    {
        return new self("Order must contain at least one media item.", 1541);
    }

    public static function invalidOriginalLink(): self
    {
        return new self('原文链接必须以 http:// 或 https:// 开头且长度不超过 2048 字符。', 1542);
    }

    public static function invalidVideoLink(): self
    {
        return new self('视频链接为必填项，必须以 http:// 或 https:// 开头且长度不超过 2048 字符，可使用网盘分享链接。', 1543);
    }

    public static function submissionQueueDisabled(): self
    {
        return new self('发布提交服务未启用，订单未创建且未冻结资金。', 1544);
    }

    public static function submissionQueueUnavailable(): self
    {
        return new self('发布提交队列不可用，订单尚未发送供应商，请勿重复下单。', 1545);
    }

    public static function submissionWorkerUnavailable(): self
    {
        return new self('发布提交 Worker 未就绪，订单未创建且未冻结资金。', 1547);
    }
}

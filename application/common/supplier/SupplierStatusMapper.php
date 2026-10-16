<?php

declare(strict_types=1);

namespace app\common\supplier;

use app\common\supplier\dto\OrderStatus;
use app\common\supplier\exception\SupplierUnknownStatusException;

final class SupplierStatusMapper
{
    private const KNOWN = [
        'queued' => OrderStatus::QUEUED,
        'submitted' => OrderStatus::SUBMITTED,
        'processing' => OrderStatus::PROCESSING,
        'published' => OrderStatus::PUBLISHED,
        'rejected' => OrderStatus::REJECTED,
        'refunded' => OrderStatus::REFUNDED,
    ];

    public static function map(string $rawStatus): string
    {
        $normalized = strtolower(trim($rawStatus));
        if (isset(self::KNOWN[$normalized])) {
            return self::KNOWN[$normalized];
        }
        throw new SupplierUnknownStatusException('Supplier returned an unknown order status.');
    }

    public static function isKnown(string $rawStatus): bool
    {
        return isset(self::KNOWN[strtolower(trim($rawStatus))]);
    }

    private function __construct()
    {
    }
}

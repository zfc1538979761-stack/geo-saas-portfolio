<?php

declare(strict_types=1);

namespace app\common\supplier;

/**
 * Portfolio demonstration pricing; no original commercial rule.
 * Only configured here; not hardcoded in controllers or pages.
 */
final class MediaPricingService
{
    private float $markupRate;

    public function __construct(?float $markupRate = null)
    {
        $this->markupRate = $markupRate ?? 1.00;
    }

    /**
     * Calculate enterprise sale price from DECIMAL cost price string.
     * Returns null if cost is zero, negative, or invalid (unsellable).
     */
    public function calculateSalePrice(string $costAmount): ?string
    {
        if ($costAmount === '' || $costAmount === '0.000000') {
            return null;
        }

        $cost = bcmul($costAmount, '1', 6);
        if (bccomp($cost, '0', 6) <= 0) {
            return null;
        }

        $sale = bcmul($cost, number_format($this->markupRate, 6, '.', ''), 6);
        // Round up to nearest fen: ceil(sale * 100) / 100
        $fen = bcmul($sale, '100', 2);
        $fenCeil = ceil((float) $fen);
        if ($fenCeil <= 0) {
            return null;
        }

        return number_format($fenCeil / 100, 2, '.', '');
    }

    public function isSellable(string $costAmount): bool
    {
        return $this->calculateSalePrice($costAmount) !== null;
    }

    public function markupRate(): float
    {
        return $this->markupRate;
    }
}

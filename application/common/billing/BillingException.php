<?php

declare(strict_types=1);

namespace app\common\billing;

use RuntimeException;

final class BillingException extends RuntimeException
{
    public static function insufficientAvailableBalance(string $available, string $required): self
    {
        return new self(
            "Insufficient available balance: {$available}, required: {$required}",
            1420
        );
    }

    public static function insufficientFrozenBalance(string $frozen, string $required, string $bizType, string $bizId): self
    {
        return new self(
            "Insufficient frozen balance for {$bizType}/{$bizId}: {$frozen}, required: {$required}",
            1421
        );
    }

    public static function frozenOverrun(string $bizType, string $bizId, string $cumulative, string $attempt): self
    {
        return new self(
            "Frozen overrun for {$bizType}/{$bizId}: cumulative={$cumulative}, attempt={$attempt}",
            1422
        );
    }

    public static function captureOverrun(string $bizType, string $bizId, string $cumulative, string $attempt): self
    {
        return new self(
            "Capture overrun for {$bizType}/{$bizId}: cumulative={$cumulative}, attempt={$attempt}",
            1423
        );
    }

    public static function refundOverrun(string $bizType, string $bizId, string $cumulative, string $attempt): self
    {
        return new self(
            "Refund overrun for {$bizType}/{$bizId}: cumulative={$cumulative}, attempt={$attempt}",
            1424
        );
    }

    public static function releaseOverrun(string $bizType, string $bizId, string $cumulative, string $attempt): self
    {
        return new self(
            "Release overrun for {$bizType}/{$bizId}: cumulative={$cumulative}, attempt={$attempt}",
            1425
        );
    }

    public static function idempotencyConflict(string $key, string $reason): self
    {
        return new self("Idempotency conflict for key '{$key}': {$reason}", 1426);
    }

    public static function invalidAmount(string $amount): self
    {
        return new self("Invalid amount: {$amount}", 1427);
    }

    public static function manualAdjustDisabled(): self
    {
        return new self('Manual balance adjustment is disabled.', 1428);
    }

    public static function walletNotFound(int $companyId): self
    {
        return new self("Wallet not found for company {$companyId}.", 1429);
    }

    public static function cannotRefundBeforeCapture(string $bizType, string $bizId): self
    {
        return new self(
            "Cannot refund frozen funds for {$bizType}/{$bizId}; only release is allowed.",
            1430
        );
    }
}

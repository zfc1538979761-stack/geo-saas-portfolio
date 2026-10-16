<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

use InvalidArgumentException;

final class CostPrice
{
    private string $amount;
    private string $currency;
    private ?string $pricingVersion;

    public function __construct(string $amount, string $currency, ?string $pricingVersion = null)
    {
        $amount = trim($amount);
        $currency = strtoupper(trim($currency));
        if (
            preg_match('/^(?:0|[1-9]\d{0,17})(?:\.\d{1,6})?$/D', $amount) !== 1
            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
        ) {
            throw new InvalidArgumentException('Cost price must be an exact decimal amount with a 3-letter currency.');
        }
        if ($pricingVersion !== null && (trim($pricingVersion) === '' || strlen($pricingVersion) > 64)) {
            throw new InvalidArgumentException('Pricing version must be a non-empty short value.');
        }
        $this->amount = $amount;
        $this->currency = $currency;
        $this->pricingVersion = $pricingVersion === null ? null : trim($pricingVersion);
    }

    public function amount(): string { return $this->amount; }
    public function currency(): string { return $this->currency; }
    public function pricingVersion(): ?string { return $this->pricingVersion; }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'pricingVersion' => $this->pricingVersion,
        ];
    }
}

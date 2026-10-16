<?php

declare(strict_types=1);

namespace app\common\ai;

use InvalidArgumentException;

final class AiCostCalculator
{
    private ?string $inputPerMillion;
    private ?string $outputPerMillion;
    private string $currency;

    public function __construct(?string $inputPerMillion, ?string $outputPerMillion, string $currency)
    {
        $this->inputPerMillion = $this->price($inputPerMillion);
        $this->outputPerMillion = $this->price($outputPerMillion);
        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('AI pricing currency is invalid.');
        }
        $this->currency = $currency;
    }

    public function calculate(?int $inputTokens, ?int $outputTokens): ?string
    {
        if ($this->inputPerMillion === null || $this->outputPerMillion === null || $inputTokens === null || $outputTokens === null) {
            return null;
        }
        if ($inputTokens < 0 || $outputTokens < 0) {
            throw new InvalidArgumentException('AI token usage is invalid.');
        }
        $input = bcmul((string) $inputTokens, $this->inputPerMillion, 12);
        $output = bcmul((string) $outputTokens, $this->outputPerMillion, 12);
        return bcdiv(bcadd($input, $output, 12), '1000000', 6);
    }

    public function currency(): string { return $this->currency; }
    public function priced(): bool { return $this->inputPerMillion !== null && $this->outputPerMillion !== null; }

    private function price(?string $value): ?string
    {
        if ($value === null || trim($value) === '') { return null; }
        $value = trim($value);
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/D', $value) !== 1) {
            throw new InvalidArgumentException('AI token price is invalid.');
        }
        return $value;
    }
}

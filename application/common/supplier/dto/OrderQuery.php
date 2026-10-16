<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

use InvalidArgumentException;

final class OrderQuery
{
    private ?string $externalOrderNo;
    private ?string $idempotencyKey;
    private ?string $channelCode;
    private ?string $catalog;

    public function __construct(
        string $externalOrderNo = '',
        string $idempotencyKey = '',
        string $channelCode = '',
        string $catalog = ''
    )
    {
        $externalOrderNo = trim($externalOrderNo);
        $idempotencyKey = trim($idempotencyKey);
        $channelCode = trim($channelCode);
        $catalog = trim($catalog);
        if ($externalOrderNo === '' && $idempotencyKey === '') {
            throw new InvalidArgumentException('Order query requires an external order number or idempotency key.');
        }
        $this->externalOrderNo = $externalOrderNo === '' ? null : $externalOrderNo;
        $this->idempotencyKey = $idempotencyKey === '' ? null : $idempotencyKey;
        $this->channelCode = $channelCode === '' ? null : $channelCode;
        $this->catalog = $catalog === '' ? null : $catalog;
    }

    public function externalOrderNo(): ?string { return $this->externalOrderNo; }
    public function idempotencyKey(): ?string { return $this->idempotencyKey; }
    public function channelCode(): ?string { return $this->channelCode; }
    public function catalog(): ?string { return $this->catalog; }

    public function toArray(): array
    {
        return [
            'externalOrderNo' => $this->externalOrderNo,
            'idempotencyKey' => $this->idempotencyKey,
            'channelCode' => $this->channelCode,
            'catalog' => $this->catalog,
        ];
    }
}

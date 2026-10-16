<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

use InvalidArgumentException;

final class MediaItem
{
    private string $supplierCode;
    private string $channelCode;
    private string $externalMediaId;
    private string $name;
    private string $status;
    private CostPrice $costPrice;
    private MediaAttributes $attributes;

    public function __construct(
        string $supplierCode,
        string $channelCode,
        string $externalMediaId,
        string $name,
        string $status,
        CostPrice $costPrice,
        MediaAttributes $attributes
    ) {
        $supplierCode = trim($supplierCode);
        $channelCode = trim($channelCode);
        $externalMediaId = trim($externalMediaId);
        $name = trim($name);
        $status = trim($status);
        if (
            $supplierCode === '' || $channelCode === '' || $externalMediaId === ''
            || $name === '' || !in_array($status, [MediaStatus::ACTIVE, MediaStatus::OFFLINE, MediaStatus::UNKNOWN], true)
        ) {
            throw new InvalidArgumentException('Media item fields are invalid.');
        }
        $this->supplierCode = $supplierCode;
        $this->channelCode = $channelCode;
        $this->externalMediaId = $externalMediaId;
        $this->name = $name;
        $this->status = $status;
        $this->costPrice = $costPrice;
        $this->attributes = $attributes;
    }

    public function supplierCode(): string { return $this->supplierCode; }
    public function channelCode(): string { return $this->channelCode; }
    public function externalMediaId(): string { return $this->externalMediaId; }
    public function name(): string { return $this->name; }
    public function status(): string { return $this->status; }
    public function costPrice(): CostPrice { return $this->costPrice; }
    public function attributes(): MediaAttributes { return $this->attributes; }
    public function priceUnconfirmed(): bool
    {
        $attributes = $this->attributes->toArray();
        if (array_key_exists('price_unconfirmed', $attributes)) {
            return (bool) $attributes['price_unconfirmed'];
        }
        return false;
    }

    public function toArray(): array
    {
        return [
            'supplierCode' => $this->supplierCode,
            'channelCode' => $this->channelCode,
            'externalMediaId' => $this->externalMediaId,
            'name' => $this->name,
            'status' => $this->status,
            'costPrice' => $this->costPrice->toArray(),
            'attributes' => $this->attributes->toArray(),
            'priceUnconfirmed' => $this->priceUnconfirmed(),
        ];
    }
}

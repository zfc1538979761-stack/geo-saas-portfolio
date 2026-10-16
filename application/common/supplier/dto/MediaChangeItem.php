<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

use InvalidArgumentException;

final class MediaChangeItem
{
    private string $channelCode;
    private string $externalMediaId;
    private string $changeType;
    private ?MediaItem $item;

    public function __construct(string $channelCode, string $externalMediaId, string $changeType, ?MediaItem $item = null)
    {
        $channelCode = trim($channelCode);
        $externalMediaId = trim($externalMediaId);
        if (
            $channelCode === '' || $externalMediaId === ''
            || !in_array($changeType, [
                MediaChangeType::NEW,
                MediaChangeType::PRICE_CHANGED,
                MediaChangeType::OFFLINE,
                MediaChangeType::UNKNOWN,
            ], true)
        ) {
            throw new InvalidArgumentException('Media change item is invalid.');
        }
        $this->channelCode = $channelCode;
        $this->externalMediaId = $externalMediaId;
        $this->changeType = $changeType;
        $this->item = $item;
    }

    public function channelCode(): string { return $this->channelCode; }
    public function externalMediaId(): string { return $this->externalMediaId; }
    public function changeType(): string { return $this->changeType; }
    public function item(): ?MediaItem { return $this->item; }

    public function toArray(): array
    {
        return [
            'channelCode' => $this->channelCode,
            'externalMediaId' => $this->externalMediaId,
            'changeType' => $this->changeType,
            'item' => $this->item === null ? null : $this->item->toArray(),
        ];
    }
}

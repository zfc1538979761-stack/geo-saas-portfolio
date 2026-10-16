<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

use InvalidArgumentException;

final class MediaChangePage
{
    private string $supplierCode;
    private array $items;
    private ?string $sinceToken;
    private ?string $nextToken;

    public function __construct(string $supplierCode, array $items, ?string $sinceToken = null, ?string $nextToken = null)
    {
        if (trim($supplierCode) === '') {
            throw new InvalidArgumentException('Media change page requires a supplier code.');
        }
        foreach ($items as $item) {
            if (!$item instanceof MediaChangeItem) {
                throw new InvalidArgumentException('Media change items must be MediaChangeItem instances.');
            }
        }
        $this->supplierCode = trim($supplierCode);
        $this->items = array_values($items);
        $this->sinceToken = $sinceToken;
        $this->nextToken = $nextToken;
    }

    public function supplierCode(): string { return $this->supplierCode; }
    public function items(): array { return $this->items; }
    public function sinceToken(): ?string { return $this->sinceToken; }
    public function nextToken(): ?string { return $this->nextToken; }

    public function toArray(): array
    {
        return [
            'supplierCode' => $this->supplierCode,
            'items' => array_map(static fn(MediaChangeItem $item): array => $item->toArray(), $this->items),
            'sinceToken' => $this->sinceToken,
            'nextToken' => $this->nextToken,
        ];
    }
}

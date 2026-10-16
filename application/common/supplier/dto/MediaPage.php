<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

use InvalidArgumentException;

final class MediaPage
{
    private string $supplierCode;
    private array $items;
    private int $total;
    private int $page;
    private int $perPage;

    public function __construct(string $supplierCode, array $items, int $total, int $page, int $perPage)
    {
        if (trim($supplierCode) === '' || $total < 0 || $page < 1 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Media page metadata is invalid.');
        }
        foreach ($items as $item) {
            if (!$item instanceof MediaItem) {
                throw new InvalidArgumentException('Media page items must be MediaItem instances.');
            }
        }
        $this->supplierCode = trim($supplierCode);
        $this->items = array_values($items);
        $this->total = $total;
        $this->page = $page;
        $this->perPage = $perPage;
    }

    public function supplierCode(): string { return $this->supplierCode; }
    public function items(): array { return $this->items; }
    public function total(): int { return $this->total; }
    public function page(): int { return $this->page; }
    public function perPage(): int { return $this->perPage; }

    public function toArray(): array
    {
        return [
            'supplierCode' => $this->supplierCode,
            'items' => array_map(static fn(MediaItem $item): array => $item->toArray(), $this->items),
            'total' => $this->total,
            'page' => $this->page,
            'perPage' => $this->perPage,
        ];
    }
}

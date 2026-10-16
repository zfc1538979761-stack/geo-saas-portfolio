<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

use InvalidArgumentException;

final class MediaQuery
{
    private string $channelCode;
    private int $page;
    private int $perPage;

    public function __construct(string $channelCode, int $page, int $perPage)
    {
        $channelCode = trim($channelCode);
        if ($channelCode === '' || $page < 1 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Media query requires a channel, a positive page, and 1-100 per page.');
        }
        $this->channelCode = $channelCode;
        $this->page = $page;
        $this->perPage = $perPage;
    }

    public function channelCode(): string { return $this->channelCode; }
    public function page(): int { return $this->page; }
    public function perPage(): int { return $this->perPage; }

    public function toArray(): array
    {
        return ['channelCode' => $this->channelCode, 'page' => $this->page, 'perPage' => $this->perPage];
    }
}

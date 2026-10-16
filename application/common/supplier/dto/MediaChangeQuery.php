<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

use InvalidArgumentException;

final class MediaChangeQuery
{
    private string $channelCode;
    private ?string $sinceToken;

    public function __construct(string $channelCode, ?string $sinceToken = null)
    {
        $channelCode = trim($channelCode);
        if ($channelCode === '') {
            throw new InvalidArgumentException('Media change query requires a channel.');
        }
        if ($sinceToken !== null && (trim($sinceToken) === '' || strlen($sinceToken) > 191)) {
            throw new InvalidArgumentException('Media change token is invalid.');
        }
        $this->channelCode = $channelCode;
        $this->sinceToken = $sinceToken === null ? null : trim($sinceToken);
    }

    public function channelCode(): string { return $this->channelCode; }
    public function sinceToken(): ?string { return $this->sinceToken; }

    public function toArray(): array
    {
        return ['channelCode' => $this->channelCode, 'sinceToken' => $this->sinceToken];
    }
}

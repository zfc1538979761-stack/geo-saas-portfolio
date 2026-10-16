<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

final class MediaStatus
{
    public const ACTIVE = 'active';
    public const OFFLINE = 'offline';
    public const UNKNOWN = 'unknown';

    private function __construct()
    {
    }
}

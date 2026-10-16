<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

final class MediaChangeType
{
    public const NEW = 'new';
    public const PRICE_CHANGED = 'price_changed';
    public const OFFLINE = 'offline';
    public const UNKNOWN = 'unknown';

    private function __construct()
    {
    }
}

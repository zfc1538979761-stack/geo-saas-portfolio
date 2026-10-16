<?php

declare(strict_types=1);

namespace app\common\content;

final class ContentRules
{
    public const KEYWORD_MAX_LENGTH = 191;
    public const QUESTION_MAX_LENGTH = 2000;
    public const SEARCH_MAX_LENGTH = 200;
    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE = 100;
    public const MAX_PAGE = 100000;
    public const MAX_BATCH_IDS = 100;
    public const STATUS_NORMAL = 'normal';
    public const STATUS_DISABLED = 'disabled';
    public const QUESTION_SOURCE_MANUAL = 'manual';

    public static function statuses(): array
    {
        return [self::STATUS_NORMAL, self::STATUS_DISABLED];
    }

    private function __construct()
    {
    }
}

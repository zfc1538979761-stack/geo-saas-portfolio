<?php

declare(strict_types=1);

namespace app\common\content\foundation;

final class ContentVersionRules
{
    public const STATUS_NORMAL = 'normal';
    public const STATUS_DISABLED = 'disabled';
    public const ARTICLE_DRAFT = 'draft';
    public const ARTICLE_DISABLED = 'disabled';
    public const SOURCE_MANUAL = 'manual';

    public static function contentStatuses(): array
    {
        return [self::STATUS_NORMAL, self::STATUS_DISABLED];
    }

    public static function articleStatuses(): array
    {
        return [self::ARTICLE_DRAFT, self::ARTICLE_DISABLED];
    }

    public static function articleSource(): string
    {
        return self::SOURCE_MANUAL;
    }

    public static function canTransitionArticle(string $from, string $to): bool
    {
        return in_array($from, self::articleStatuses(), true)
            && in_array($to, self::articleStatuses(), true)
            && ($from === $to || $from !== $to);
    }

    private function __construct()
    {
    }
}

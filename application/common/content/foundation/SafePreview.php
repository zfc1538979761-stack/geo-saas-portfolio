<?php

declare(strict_types=1);

namespace app\common\content\foundation;

final class SafePreview
{
    public static function plainText(string $content): string
    {
        return nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    private function __construct()
    {
    }
}

<?php

declare(strict_types=1);

namespace app\common\identity;

use app\common\foundation\SensitiveDataRedactor;
use think\Log;

final class ThinkSecurityEventLogger implements SecurityEventLogger
{
    public function record(string $event, array $context): void
    {
        $payload = SensitiveDataRedactor::redact([
            'category' => 'security',
            'event' => $event,
            'context' => $context,
        ]);
        Log::record(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'notice'
        );
    }
}

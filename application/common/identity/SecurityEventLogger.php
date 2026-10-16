<?php

declare(strict_types=1);

namespace app\common\identity;

interface SecurityEventLogger
{
    public function record(string $event, array $context): void;
}

<?php

declare(strict_types=1);

namespace app\common\geo;

interface VisibilityTaskDispatcher
{
    public function dispatch(array $envelope, string $queue): void;
}

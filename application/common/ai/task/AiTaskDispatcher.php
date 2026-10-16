<?php

declare(strict_types=1);

namespace app\common\ai\task;

interface AiTaskDispatcher
{
    public function dispatch(array $envelope, string $queue): void;
}

<?php

declare(strict_types=1);

namespace app\common\geo;

use app\common\job\GeoVisibilityCheckJob;
use RuntimeException;
use think\Queue;

final class ThinkQueueVisibilityTaskDispatcher implements VisibilityTaskDispatcher
{
    public function dispatch(array $envelope, string $queue): void
    {
        if (Queue::push(GeoVisibilityCheckJob::class, $envelope, $queue) === false) {
            throw new RuntimeException('Visibility task dispatch failed.');
        }
    }
}

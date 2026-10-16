<?php

declare(strict_types=1);

namespace app\common\ai\task;

use app\common\job\AiArticleGenerationJob;
use RuntimeException;
use think\Queue;

final class ThinkQueueAiTaskDispatcher implements AiTaskDispatcher
{
    public function dispatch(array $envelope, string $queue): void
    {
        $id = Queue::push(AiArticleGenerationJob::class, $envelope, $queue);
        if ($id === false) { throw new RuntimeException('AI task dispatch failed.'); }
    }
}

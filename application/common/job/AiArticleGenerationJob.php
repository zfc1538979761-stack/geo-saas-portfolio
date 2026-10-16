<?php

declare(strict_types=1);

namespace app\common\job;

use app\common\ai\task\AiTaskRuntimeFactory;
use app\common\tenant\TenantException;

final class AiArticleGenerationJob extends AbstractQueueJob
{
    protected function handle(array $payload): void
    {
        $context = AiTaskRuntimeFactory::envelope()->resolve($payload);
        if (($payload['task'] ?? '') !== 'ai_article_generation') { throw new TenantException('AI queue task type is invalid.'); }
        $taskPayload = $payload['payload'] ?? null;
        if (!is_array($taskPayload) || filter_var($taskPayload['job_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new TenantException('AI queue payload is invalid.');
        }
        AiTaskRuntimeFactory::runner()->run((int) $taskPayload['job_id'], $context);
    }

    protected function policy(): QueuePolicy
    {
        $config = AiTaskRuntimeFactory::config();
        return new QueuePolicy(
            max(1, min(10, (int) ($config['max_attempts'] ?? 3))),
            array_values((array) ($config['retry_delays'] ?? [5, 30, 120])),
            8192
        );
    }
}

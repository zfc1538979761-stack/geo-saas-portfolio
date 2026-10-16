<?php

declare(strict_types=1);

namespace app\common\job;

use app\common\ai\task\AiTaskRuntimeFactory;
use app\common\geo\GeoVisibilityRuntimeFactory;
use app\common\tenant\TenantException;

final class GeoVisibilityCheckJob extends AbstractQueueJob
{
    protected function handle(array $payload): void
    {
        $context = AiTaskRuntimeFactory::envelope()->resolve($payload);
        if (($payload['task'] ?? '') !== 'geo_visibility_check') { throw new TenantException('Visibility queue task type is invalid.'); }
        $taskPayload = $payload['payload'] ?? null;
        if (!is_array($taskPayload) || filter_var($taskPayload['job_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new TenantException('Visibility queue payload is invalid.');
        }
        GeoVisibilityRuntimeFactory::runner()->run((int) $taskPayload['job_id'], $context);
    }

    protected function policy(): QueuePolicy
    {
        $config = GeoVisibilityRuntimeFactory::config();
        return new QueuePolicy(
            max(1, min(10, (int) ($config['max_attempts'] ?? 3))),
            array_values((array) ($config['retry_delays'] ?? [5, 30, 120])),
            8192
        );
    }
}

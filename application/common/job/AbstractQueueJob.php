<?php

declare(strict_types=1);

namespace app\common\job;

use InvalidArgumentException;
use JsonException;
use think\Log;
use think\queue\Job;
use Throwable;

abstract class AbstractQueueJob
{
    final public function fire(Job $job, $data): void
    {
        try {
            $payload = $this->validatedPayload($data);
            $this->handle($payload);
            $job->delete();
        } catch (RetryableJobException $exception) {
            $this->handleRetryableFailure($job, $exception);
        } catch (Throwable $exception) {
            $job->delete();
            $this->recordSafeFailure($exception, 'non_retryable');
            throw $exception;
        }
    }

    abstract protected function handle(array $payload): void;

    protected function policy(): QueuePolicy
    {
        return new QueuePolicy();
    }

    private function validatedPayload($data): array
    {
        if (!is_array($data)) {
            throw new InvalidArgumentException('Queue payload must be an array.');
        }

        try {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Queue payload must be valid JSON data.', 0, $exception);
        }

        if (strlen($encoded) > $this->policy()->maxPayloadBytes()) {
            throw new InvalidArgumentException('Queue payload exceeds the configured size limit.');
        }

        return $data;
    }

    private function handleRetryableFailure(Job $job, RetryableJobException $exception): void
    {
        $attempt = max(1, (int) $job->attempts());
        if ($attempt >= $this->policy()->maxAttempts()) {
            $job->delete();
            $this->recordSafeFailure($exception, 'retry_exhausted');
            return;
        }

        $job->release($this->policy()->delayForAttempt($attempt));
    }

    private function recordSafeFailure(Throwable $exception, string $category): void
    {
        Log::record(
            'queue_job_failure category=' . $category
            . ' exception=' . str_replace('\\', '.', get_class($exception)),
            'error'
        );
    }
}

<?php

declare(strict_types=1);

namespace app\common\publishing;

use Throwable;

final class PublishSubmitDispatcher
{
    private bool $enabled;
    /** @var callable */
    private $enqueue;
    /** @var callable|null */
    private $readiness;

    public function __construct(bool $enabled, callable $enqueue, ?callable $readiness = null)
    {
        $this->enabled = $enabled;
        $this->enqueue = $enqueue;
        $this->readiness = $readiness;
    }

    public function assertReady(): void
    {
        if (!$this->enabled) {
            throw PublishException::submissionQueueDisabled();
        }
        if ($this->readiness !== null && !($this->readiness)()) {
            throw PublishException::submissionWorkerUnavailable();
        }
    }

    public function dispatch(
        int $companyId,
        int $orderId,
        callable $markQueued,
        callable $markFailed
    ): array {
        $this->assertReady();

        try {
            $markQueued();
            $jobId = ($this->enqueue)(
                ['company_id' => $companyId, 'order_id' => $orderId],
                'geo_publish_submit'
            );
            if ($jobId === false || $jobId === null || $jobId === '') {
                throw PublishException::submissionQueueUnavailable();
            }
        } catch (Throwable $exception) {
            $markFailed();
            return [
                'state' => 'queue_failed',
                'job_id' => null,
                'error_class' => get_class($exception),
            ];
        }

        return ['state' => 'queued', 'job_id' => (string) $jobId];
    }
}

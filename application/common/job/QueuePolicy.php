<?php

declare(strict_types=1);

namespace app\common\job;

use InvalidArgumentException;

final class QueuePolicy
{
    private $maxAttempts;
    private $retryDelays;
    private $maxPayloadBytes;

    public function __construct(
        int $maxAttempts = 3,
        array $retryDelays = [5, 30, 120],
        int $maxPayloadBytes = 65536
    ) {
        if ($maxAttempts < 1 || $retryDelays === [] || $maxPayloadBytes < 1) {
            throw new InvalidArgumentException('Queue policy values must be positive.');
        }
        foreach ($retryDelays as $delay) {
            if (!is_int($delay) || $delay < 0) {
                throw new InvalidArgumentException('Queue retry delays must be non-negative integers.');
            }
        }

        $this->maxAttempts = $maxAttempts;
        $this->retryDelays = array_values($retryDelays);
        $this->maxPayloadBytes = $maxPayloadBytes;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function delayForAttempt(int $attempt): int
    {
        $index = max(0, min(count($this->retryDelays) - 1, $attempt - 1));
        return $this->retryDelays[$index];
    }

    public function maxPayloadBytes(): int
    {
        return $this->maxPayloadBytes;
    }
}

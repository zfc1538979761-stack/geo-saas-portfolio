<?php

declare(strict_types=1);

namespace app\common\identity;

interface LoginAttemptStore
{
    public function reserveAttempt(
        string $key,
        int $now,
        int $maximumFailures,
        int $windowSeconds,
        int $lockoutSeconds
    ): bool;

    public function delete(string $key): void;
}

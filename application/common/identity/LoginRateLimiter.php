<?php

declare(strict_types=1);

namespace app\common\identity;

final class LoginRateLimiter
{
    private $store;
    private $secret;
    private $maximumFailures;
    private $windowSeconds;
    private $lockoutSeconds;
    private $clock;

    public function __construct(
        LoginAttemptStore $store,
        string $secret,
        int $maximumFailures = 5,
        int $windowSeconds = 900,
        int $lockoutSeconds = 900,
        ?callable $clock = null
    ) {
        if ($secret === '' || $maximumFailures < 1 || $windowSeconds < 1 || $lockoutSeconds < 1) {
            throw new \InvalidArgumentException('Invalid login rate limit configuration.');
        }

        $this->store = $store;
        $this->secret = $secret;
        $this->maximumFailures = $maximumFailures;
        $this->windowSeconds = $windowSeconds;
        $this->lockoutSeconds = $lockoutSeconds;
        $this->clock = $clock ?: static function (): int {
            return time();
        };
    }

    public function assertAllowed(string $account, string $ip): void
    {
        $key = $this->key($account, $ip);
        $now = $this->now();
        if (!$this->store->reserveAttempt(
            $key,
            $now,
            $this->maximumFailures,
            $this->windowSeconds,
            $this->lockoutSeconds
        )) {
            throw new IdentityException(1429, '登录尝试过于频繁，请稍后再试', 429);
        }
    }

    public function clear(string $account, string $ip): void
    {
        $this->store->delete($this->key($account, $ip));
    }

    private function key(string $account, string $ip): string
    {
        return hash_hmac('sha256', strtolower(trim($account)) . '|' . $ip, $this->secret);
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }
}

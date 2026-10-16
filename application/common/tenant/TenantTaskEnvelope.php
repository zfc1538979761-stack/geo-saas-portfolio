<?php

declare(strict_types=1);

namespace app\common\tenant;

final class TenantTaskEnvelope
{
    private const VERSION = 1;
    private const MAX_AGE_SECONDS = 86400;

    private $secret;
    private $clock;

    public function __construct(string $secret, ?callable $clock = null)
    {
        if (strlen($secret) < 32) {
            throw new TenantException('Tenant task signing key is too short.');
        }
        $this->secret = $secret;
        $this->clock = $clock ?: static function (): int {
            return time();
        };
    }

    public function issue(TenantContext $context, string $task, array $payload): array
    {
        $companyId = $context->companyId();
        if ($context->canBypass() || $companyId === null || $companyId < 1 || trim($task) === '') {
            throw new TenantException('Tenant task requires a concrete trusted tenant.');
        }
        $envelope = [
            'version' => self::VERSION,
            'actor_id' => $context->actorId(),
            'company_id' => $companyId,
            'task' => trim($task),
            'payload' => $payload,
            'issued_at' => $this->now(),
            'nonce' => bin2hex(random_bytes(16)),
        ];
        $envelope['signature'] = $this->signature($envelope);
        return $envelope;
    }

    public function resolve(array $envelope): TenantContext
    {
        $signature = (string) ($envelope['signature'] ?? '');
        unset($envelope['signature']);
        $issuedAt = (int) ($envelope['issued_at'] ?? 0);
        $now = $this->now();
        if (
            (int) ($envelope['version'] ?? 0) !== self::VERSION
            || (int) ($envelope['actor_id'] ?? 0) < 1
            || (int) ($envelope['company_id'] ?? 0) < 1
            || trim((string) ($envelope['task'] ?? '')) === ''
            || !is_array($envelope['payload'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/', (string) ($envelope['nonce'] ?? '')) !== 1
            || $issuedAt > $now + 60
            || $issuedAt < $now - self::MAX_AGE_SECONDS
            || $signature === ''
            || !hash_equals($this->signature($envelope), $signature)
        ) {
            throw new TenantException('Tenant task envelope is invalid.');
        }

        return TenantContext::enterprise(
            (int) $envelope['actor_id'],
            (int) $envelope['company_id'],
            'trusted_task_envelope'
        );
    }

    private function signature(array $envelope): string
    {
        $canonical = $this->canonicalize($envelope);
        return hash_hmac(
            'sha256',
            json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $this->secret
        );
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }
        if ($this->isAssociative($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }

    private function isAssociative(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }
}

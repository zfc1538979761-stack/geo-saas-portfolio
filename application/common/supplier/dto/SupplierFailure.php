<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

use app\common\foundation\SensitiveDataRedactor;

final class SupplierFailure
{
    private string $code;
    private string $redactedReason;
    private bool $retryable;

    public function __construct(string $code, string $reason, bool $retryable)
    {
        $this->code = trim($code);
        $reason = (string) preg_replace(
            '/((?:password|passwd|pwd|secret|token|api[_-]?key|access[_-]?code|cookie)\s*[=:]\s*)[^\s,;]+/i',
            '$1[REDACTED]',
            $reason
        );
        $this->redactedReason = (string) SensitiveDataRedactor::redact($reason);
        $this->retryable = $retryable;
    }

    public function code(): string { return $this->code; }
    public function redactedReason(): string { return $this->redactedReason; }
    public function retryable(): bool { return $this->retryable; }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'redactedReason' => $this->redactedReason,
            'retryable' => $this->retryable,
        ];
    }
}

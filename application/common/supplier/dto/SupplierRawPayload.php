<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

use app\common\foundation\SensitiveDataRedactor;

final class SupplierRawPayload
{
    private array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function all(): array
    {
        return $this->payload;
    }

    public function redacted(): array
    {
        return SensitiveDataRedactor::redact($this->payload);
    }

    public function toArray(): array
    {
        return $this->redacted();
    }
}

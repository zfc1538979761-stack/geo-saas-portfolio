<?php

declare(strict_types=1);

namespace app\common\geo\visibility;

use RuntimeException;

final class VisibilityProviderException extends RuntimeException
{
    private string $category;
    private string $providerCode;
    private string $externalCode;
    private bool $retryable;
    private bool $networkExecuted;

    public function __construct(
        string $message,
        string $category,
        string $providerCode,
        string $externalCode = '',
        bool $retryable = false,
        bool $networkExecuted = false
    ) {
        parent::__construct($message);
        $this->category = $category;
        $this->providerCode = $providerCode;
        $this->externalCode = $externalCode;
        $this->retryable = $retryable;
        $this->networkExecuted = $networkExecuted;
    }

    public function category(): string { return $this->category; }
    public function providerCode(): string { return $this->providerCode; }
    public function externalCode(): string { return $this->externalCode; }
    public function retryable(): bool { return $this->retryable; }
    public function networkExecuted(): bool { return $this->networkExecuted; }
}

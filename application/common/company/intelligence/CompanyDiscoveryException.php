<?php

declare(strict_types=1);

namespace app\common\company\intelligence;

use RuntimeException;

class CompanyDiscoveryException extends RuntimeException
{
    private string $category;
    private int $httpStatus;
    private ?string $providerCode;
    private ?string $externalCode;
    private bool $retryable;
    private bool $networkExecuted;
    private ?array $details;

    public function __construct(
        string $category,
        string $message,
        int $httpStatus,
        ?string $providerCode = null,
        ?string $externalCode = null,
        bool $retryable = false,
        bool $networkExecuted = false,
        ?array $details = null
    ) {
        parent::__construct($message);
        $this->category = $category;
        $this->httpStatus = $httpStatus;
        $this->providerCode = $providerCode;
        $this->externalCode = $externalCode;
        $this->retryable = $retryable;
        $this->networkExecuted = $networkExecuted;
        $this->details = $details;
    }

    public function category(): string { return $this->category; }
    public function httpStatus(): int { return $this->httpStatus; }
    public function providerCode(): ?string { return $this->providerCode; }
    public function externalCode(): ?string { return $this->externalCode; }
    public function retryable(): bool { return $this->retryable; }
    public function networkExecuted(): bool { return $this->networkExecuted; }
    public function details(): ?array { return $this->details; }
}

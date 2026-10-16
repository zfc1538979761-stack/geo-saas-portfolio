<?php

declare(strict_types=1);

namespace app\common\ai\exception;

use RuntimeException;

class AiProviderException extends RuntimeException
{
    private string $category;
    private bool $retryable;
    private int $httpStatus;
    private string $requestId;

    public function __construct(
        string $safeMessage,
        string $category,
        bool $retryable,
        int $httpStatus = 0,
        string $requestId = ''
    ) {
        parent::__construct($safeMessage, $httpStatus);
        $this->category = $category;
        $this->retryable = $retryable;
        $this->httpStatus = $httpStatus;
        $this->requestId = $requestId;
    }

    public function category(): string { return $this->category; }
    public function retryable(): bool { return $this->retryable; }
    public function httpStatus(): int { return $this->httpStatus; }
    public function requestId(): string { return $this->requestId; }
}

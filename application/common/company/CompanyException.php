<?php

declare(strict_types=1);

namespace app\common\company;

use RuntimeException;

final class CompanyException extends RuntimeException
{
    private $errorCode;
    private $httpStatus;
    private $details;

    public function __construct(
        int $errorCode,
        string $message,
        int $httpStatus = 422,
        ?array $details = null
    ) {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->details = $details;
    }

    public function errorCode(): int
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function details(): ?array
    {
        return $this->details;
    }
}

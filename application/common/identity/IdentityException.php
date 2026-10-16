<?php

declare(strict_types=1);

namespace app\common\identity;

use RuntimeException;

final class IdentityException extends RuntimeException
{
    private $errorCode;
    private $httpStatus;

    public function __construct(int $errorCode, string $message, int $httpStatus)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
    }

    public function errorCode(): int
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

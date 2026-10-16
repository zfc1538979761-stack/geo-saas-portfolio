<?php

declare(strict_types=1);

namespace app\common\supplier\exception;

final class SupplierAuthenticationException extends SupplierException
{
    public function __construct(string $safeMessage, int $httpStatus = 0, string $requestId = '')
    {
        parent::__construct($safeMessage, 'authentication_failed', false, $httpStatus, $requestId);
    }
}

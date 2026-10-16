<?php

declare(strict_types=1);

namespace app\common\supplier\exception;

final class SupplierServerException extends SupplierException
{
    public function __construct(string $safeMessage, int $httpStatus = 500, string $requestId = '')
    {
        parent::__construct($safeMessage, 'provider_server_error', true, $httpStatus, $requestId);
    }
}

<?php

declare(strict_types=1);

namespace app\common\supplier\exception;

final class SupplierInvalidResponseException extends SupplierException
{
    public function __construct(string $safeMessage, string $requestId = '')
    {
        parent::__construct($safeMessage, 'invalid_response', false, 0, $requestId);
    }
}

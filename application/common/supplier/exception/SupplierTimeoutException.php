<?php

declare(strict_types=1);

namespace app\common\supplier\exception;

final class SupplierTimeoutException extends SupplierException
{
    public function __construct(string $safeMessage, string $requestId = '')
    {
        parent::__construct($safeMessage, 'timeout', true, 0, $requestId);
    }
}

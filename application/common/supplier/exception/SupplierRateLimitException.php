<?php

declare(strict_types=1);

namespace app\common\supplier\exception;

final class SupplierRateLimitException extends SupplierException
{
    public function __construct(string $safeMessage, int $httpStatus = 429, string $requestId = '')
    {
        parent::__construct($safeMessage, 'rate_limited', true, $httpStatus, $requestId);
    }
}

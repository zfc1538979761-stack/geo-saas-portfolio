<?php

declare(strict_types=1);

namespace app\common\supplier\exception;

final class SupplierPartialFailureException extends SupplierException
{
    public function __construct(string $safeMessage, string $requestId = '')
    {
        parent::__construct($safeMessage, 'partial_failure', false, 0, $requestId);
    }
}

<?php

declare(strict_types=1);

namespace app\common\supplier\exception;

final class SupplierDuplicateOrderException extends SupplierException
{
    public function __construct(string $safeMessage, string $requestId = '')
    {
        parent::__construct($safeMessage, 'duplicate_external_order', false, 0, $requestId);
    }
}

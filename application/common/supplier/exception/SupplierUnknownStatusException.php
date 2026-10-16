<?php

declare(strict_types=1);

namespace app\common\supplier\exception;

final class SupplierUnknownStatusException extends SupplierException
{
    public function __construct(string $safeMessage, string $requestId = '')
    {
        parent::__construct($safeMessage, 'unknown_supplier_status', false, 0, $requestId);
    }
}

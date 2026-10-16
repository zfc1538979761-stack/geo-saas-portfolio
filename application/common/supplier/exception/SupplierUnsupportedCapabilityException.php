<?php

declare(strict_types=1);

namespace app\common\supplier\exception;

final class SupplierUnsupportedCapabilityException extends SupplierException
{
    public function __construct(string $safeMessage, string $requestId = '')
    {
        parent::__construct($safeMessage, 'unsupported_capability', false, 0, $requestId);
    }
}

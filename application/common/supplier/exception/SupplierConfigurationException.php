<?php

declare(strict_types=1);

namespace app\common\supplier\exception;

final class SupplierConfigurationException extends SupplierException
{
    public function __construct(string $safeMessage)
    {
        parent::__construct($safeMessage, 'configuration_missing', false);
    }
}

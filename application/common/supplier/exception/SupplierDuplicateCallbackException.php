<?php

declare(strict_types=1);

namespace app\common\supplier\exception;

final class SupplierDuplicateCallbackException extends SupplierException
{
    public function __construct(string $safeMessage, string $eventId = '')
    {
        parent::__construct($safeMessage, 'duplicate_callback_event', false, 0, $eventId);
    }
}

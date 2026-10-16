<?php

declare(strict_types=1);

namespace app\common\supplier\dto;

final class CallbackEventType
{
    public const ORDER_STATUS_CHANGED = 'order_status_changed';
    public const ORDER_REJECTED = 'order_rejected';
    public const ORDER_REFUNDED = 'order_refunded';
    public const UNKNOWN = 'unknown';

    private function __construct()
    {
    }
}

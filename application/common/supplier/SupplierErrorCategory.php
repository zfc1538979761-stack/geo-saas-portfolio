<?php

declare(strict_types=1);

namespace app\common\supplier;

final class SupplierErrorCategory
{
    public const CONFIGURATION_MISSING = 'configuration_missing';
    public const AUTHENTICATION_FAILED = 'authentication_failed';
    public const TIMEOUT = 'timeout';
    public const RATE_LIMITED = 'rate_limited';
    public const PROVIDER_SERVER_ERROR = 'provider_server_error';
    public const INVALID_RESPONSE = 'invalid_response';
    public const DUPLICATE_EXTERNAL_ORDER = 'duplicate_external_order';
    public const UNKNOWN_SUPPLIER_STATUS = 'unknown_supplier_status';
    public const UNSUPPORTED_CAPABILITY = 'unsupported_capability';
    public const PARTIAL_FAILURE = 'partial_failure';
    public const DUPLICATE_CALLBACK_EVENT = 'duplicate_callback_event';

    private function __construct()
    {
    }
}

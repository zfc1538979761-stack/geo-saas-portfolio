<?php

declare(strict_types=1);

namespace app\common\foundation;

final class ApiErrorCode
{
    public const OK = 0;
    public const INVALID_REQUEST = 1001;
    public const UNAUTHENTICATED = 1401;
    public const FORBIDDEN = 1403;
    public const NOT_FOUND = 1404;
    public const TOO_MANY_REQUESTS = 1429;
    public const DEPENDENCY_UNAVAILABLE = 1503;
    public const INTERNAL_ERROR = 1500;

    private function __construct()
    {
    }
}

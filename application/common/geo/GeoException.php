<?php

declare(strict_types=1);

namespace app\common\geo;

use RuntimeException;

final class GeoException extends RuntimeException
{
    private int $httpStatus;

    public function __construct(string $message, int $httpStatus = 422)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }

    public function httpStatus(): int { return $this->httpStatus; }
}

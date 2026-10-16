<?php

declare(strict_types=1);

namespace app\common\ai\task;

use RuntimeException;

final class AiTaskException extends RuntimeException
{
    private int $httpStatus;

    public function __construct(string $safeMessage, int $httpStatus = 422)
    {
        parent::__construct($safeMessage);
        $this->httpStatus = $httpStatus;
    }

    public function httpStatus(): int { return $this->httpStatus; }
}

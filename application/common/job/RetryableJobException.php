<?php

declare(strict_types=1);

namespace app\common\job;

use RuntimeException;

final class RetryableJobException extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace app\common\identity;

final class SynchronousIdentityTransaction implements IdentityTransaction
{
    public function run(callable $operation)
    {
        return $operation();
    }
}

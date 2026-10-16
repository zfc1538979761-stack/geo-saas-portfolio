<?php

declare(strict_types=1);

namespace app\common\identity;

interface IdentityTransaction
{
    public function run(callable $operation);
}

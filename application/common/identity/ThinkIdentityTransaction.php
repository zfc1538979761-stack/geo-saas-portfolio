<?php

declare(strict_types=1);

namespace app\common\identity;

use think\Db;
use Throwable;

final class ThinkIdentityTransaction implements IdentityTransaction
{
    public function run(callable $operation)
    {
        Db::startTrans();
        try {
            $result = $operation();
            Db::commit();
            return $result;
        } catch (Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }
}

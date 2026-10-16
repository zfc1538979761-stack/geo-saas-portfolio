<?php

declare(strict_types=1);

namespace app\common\publishing;

use app\common\billing\BillingServiceFactory;
use app\common\tenant\PdoAuditLogger;
use think\Config;
use think\Db;

final class PublishOrderFactory
{
    public static function create(): PublishOrderService
    {
        $prefix = Config::get("database.prefix") ?: "fa_";
        return new PublishOrderService(
            Db::connect()->getPdo(),
            BillingServiceFactory::create(),
            $prefix
        );
    }

    private function __construct()
    {
    }
}

<?php

declare(strict_types=1);

namespace app\common\billing;

use app\common\tenant\PdoAuditLogger;
use think\Config;
use think\Db;

final class BillingServiceFactory
{
    public static function create(): BillingService
    {
        $prefix = Config::get('database.prefix') ?: 'fa_';
        $pdo = Db::connect()->connect();
        return new BillingService(
            $pdo,
            new PdoAuditLogger($pdo, $prefix),
            $prefix
        );
    }

    private function __construct()
    {
    }
}

<?php

declare(strict_types=1);

namespace app\common\internal;

final class InternalAccessPolicy
{
    private const HIGH_RISK_PERMISSIONS = [
        'internal/ai_config/replace',
        'internal/supplier_config/replace',
        'internal/media_sync/trigger',
        'internal/company/status',
        'internal/wallet/credit',
        'internal/wallet/debit',
        'internal/job/replay',
        'internal/order/reprocess',
        'internal/monitor/ack',
        'internal/webhook/replay',
    ];

    public static function permission(string $controller, string $action): string
    {
        $controller = strtolower(trim(preg_replace('/(?<!^)[A-Z]/', '_$0', $controller), '_'));
        $action = strtolower(trim($action, '_'));
        if ($controller === '' || $action === '') {
            throw new \InvalidArgumentException('Internal permission path is invalid.');
        }
        return 'internal/' . $controller . '/' . $action;
    }

    public static function isHighRisk(string $permission): bool
    {
        return in_array($permission, self::HIGH_RISK_PERMISSIONS, true);
    }

    private function __construct()
    {
    }
}

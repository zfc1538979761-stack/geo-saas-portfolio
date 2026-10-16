<?php

declare(strict_types=1);

namespace app\common\tenant;

use ReflectionClass;

final class TenantResourceGuard
{
    public static function assertCompliant(string $className, TenantTableRegistry $registry): void
    {
        if (!class_exists($className)) {
            throw new TenantException('Tenant resource class does not exist.');
        }
        $reflection = new ReflectionClass($className);
        if (!$reflection->implementsInterface(TenantOwnedResource::class)) {
            throw new TenantException('Tenant resource must implement the tenant-owned contract.');
        }
        if (!$reflection->isInstantiable()) {
            throw new TenantException('Tenant resource class is not instantiable.');
        }
        $resource = $reflection->newInstance()->tenantResourceName();
        $registry->require($resource);
    }
}

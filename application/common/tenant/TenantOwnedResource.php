<?php

declare(strict_types=1);

namespace app\common\tenant;

interface TenantOwnedResource
{
    public function tenantResourceName(): string;
}

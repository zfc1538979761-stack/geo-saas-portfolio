<?php

declare(strict_types=1);

namespace app\common\tenant;

interface TenantIdentityProvider
{
    public function resolve(string $token): array;
}

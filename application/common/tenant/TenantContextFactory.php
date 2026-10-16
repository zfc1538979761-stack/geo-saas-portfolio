<?php

declare(strict_types=1);

namespace app\common\tenant;

final class TenantContextFactory
{
    public static function fromEnterpriseToken(string $token): TenantContext
    {
        return (new TenantContextResolver(new EnterpriseTokenIdentityProvider()))->resolve($token);
    }
}

<?php

declare(strict_types=1);

namespace app\common\tenant;

use app\common\identity\IdentityException;
use app\common\identity\IdentityService;
use app\common\identity\IdentityServiceFactory;

final class EnterpriseTokenIdentityProvider implements TenantIdentityProvider
{
    private $identityService;

    public function __construct(?IdentityService $identityService = null)
    {
        $this->identityService = $identityService ?: IdentityServiceFactory::create();
    }

    public function resolve(string $token): array
    {
        try {
            return $this->identityService->current($token);
        } catch (IdentityException $exception) {
            throw new TenantException('Unauthenticated tenant context.', 0, $exception);
        }
    }
}

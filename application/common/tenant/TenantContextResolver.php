<?php

declare(strict_types=1);

namespace app\common\tenant;

final class TenantContextResolver
{
    private $identityProvider;

    public function __construct(TenantIdentityProvider $identityProvider)
    {
        $this->identityProvider = $identityProvider;
    }

    public function resolve(string $token): TenantContext
    {
        if ($token === '') {
            throw new TenantException('Unauthenticated tenant context.');
        }

        $identity = $this->identityProvider->resolve($token);
        $actorId = (int) ($identity['id'] ?? 0);
        $company = is_array($identity['company'] ?? null) ? $identity['company'] : [];
        $companyId = (int) ($company['id'] ?? 0);
        if (
            $actorId < 1
            || $companyId < 1
            || ($identity['status'] ?? '') !== 'normal'
            || ($company['status'] ?? '') !== 'normal'
        ) {
            throw new TenantException('Unauthenticated tenant context.');
        }

        return TenantContext::enterprise($actorId, $companyId);
    }

    public static function fromTrustedIdentity(int $actorId, int $companyId, string $source): TenantContext
    {
        if ($actorId < 1 || $companyId < 1 || trim($source) === '') {
            throw new TenantException('Trusted tenant identity is invalid.');
        }
        return TenantContext::enterprise($actorId, $companyId, $source);
    }
}

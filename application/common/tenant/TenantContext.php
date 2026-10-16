<?php

declare(strict_types=1);

namespace app\common\tenant;

final class TenantContext
{
    private $actorId;
    private $companyId;
    private $actorType;
    private $source;
    private $bypass;

    private function __construct(
        int $actorId,
        ?int $companyId,
        string $actorType,
        string $source,
        bool $bypass
    ) {
        if ($actorId < 1) {
            throw new TenantException('Tenant actor is invalid.');
        }
        if (!$bypass && ($companyId === null || $companyId < 1)) {
            throw new TenantException('Tenant company is invalid.');
        }

        $this->actorId = $actorId;
        $this->companyId = $companyId;
        $this->actorType = $actorType;
        $this->source = $source;
        $this->bypass = $bypass;
    }

    public static function enterprise(int $actorId, int $companyId, string $source = 'authenticated_session'): self
    {
        return new self($actorId, $companyId, 'enterprise_user', $source, false);
    }

    public static function authorizeInternalBypass(
        InternalAdminIdentity $identity,
        string $reason,
        string $ip,
        AuditLogger $auditLogger
    ): self {
        $reason = trim($reason);
        if (!$identity->hasPermission(TenantBypassAuthorizer::PERMISSION) || $reason === '') {
            throw new TenantException('Tenant bypass is not authorized.');
        }
        $context = new self($identity->id(), null, 'internal_admin', 'controlled_bypass', true);
        $auditLogger->record(
            'tenant_scope_bypass_granted',
            $context,
            'tenant_scope',
            null,
            $ip,
            ['reason' => $reason]
        );
        return $context;
    }

    public function actorId(): int
    {
        return $this->actorId;
    }

    public function companyId(): ?int
    {
        return $this->companyId;
    }

    public function actorType(): string
    {
        return $this->actorType;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function canBypass(): bool
    {
        return $this->bypass;
    }
}

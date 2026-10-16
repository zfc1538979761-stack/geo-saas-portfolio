<?php

declare(strict_types=1);

namespace app\common\tenant;

final class TenantBypassAuthorizer
{
    public const PERMISSION = 'tenant.scope.bypass';

    private $auditLogger;

    public function __construct(AuditLogger $auditLogger)
    {
        $this->auditLogger = $auditLogger;
    }

    public function authorize(InternalAdminIdentity $identity, string $reason, string $ip): TenantContext
    {
        return TenantContext::authorizeInternalBypass($identity, $reason, $ip, $this->auditLogger);
    }
}

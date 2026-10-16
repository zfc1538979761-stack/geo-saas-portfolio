<?php

declare(strict_types=1);

namespace app\common\tenant;

interface AuditLogger
{
    public function record(
        string $action,
        ?TenantContext $context,
        string $targetType,
        ?string $targetId,
        string $ip,
        array $detail = []
    ): void;
}

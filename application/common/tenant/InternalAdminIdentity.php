<?php

declare(strict_types=1);

namespace app\common\tenant;

final class InternalAdminIdentity
{
    private $id;
    private $permissions;

    public function __construct(int $id, array $permissions)
    {
        if ($id < 1) {
            throw new TenantException('Internal administrator identity is invalid.');
        }
        $this->id = $id;
        $this->permissions = array_values(array_unique(array_map('strval', $permissions)));
    }

    public function id(): int
    {
        return $this->id;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}

<?php

declare(strict_types=1);

namespace app\common\tenant;

final class TenantTableRegistry
{
    private $definitions;

    public function __construct(array $definitions)
    {
        $this->definitions = [];
        foreach ($definitions as $resource => $definition) {
            if (
                preg_match('/^[A-Za-z0-9_.-]+$/', (string) $resource) !== 1
                || !$definition instanceof TenantTableDefinition
            ) {
                throw new TenantException('Tenant resource registration is invalid.');
            }
            $this->definitions[(string) $resource] = $definition;
        }
    }

    public function require(string $resource): TenantTableDefinition
    {
        if (!isset($this->definitions[$resource])) {
            throw new TenantException('Tenant resource is not registered.');
        }
        return $this->definitions[$resource];
    }
}

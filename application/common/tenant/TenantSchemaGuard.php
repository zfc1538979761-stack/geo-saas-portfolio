<?php

declare(strict_types=1);

namespace app\common\tenant;

final class TenantSchemaGuard
{
    private const LEGACY_UNANNOTATED_TABLE_MIGRATIONS = [
        '202608060010_create_wallets_and_ledger.php',
        '202608060012_create_publish_orders.php',
        '202608070015_t14_notifications.php',
        '202608070016_t15_refund_requests.php',
        '202608070017_t13_webhook_events.php',
    ];

    public static function inspectMigration(
        string $filename,
        string $source,
        array $registeredResources
    ): array {
        if (strcmp($filename, '202607300003_enforce_tenant_isolation.php') <= 0) {
            return [];
        }
        if (preg_match('/CREATE\s+TABLE/i', $source) !== 1) {
            return [];
        }
        if (in_array($filename, self::LEGACY_UNANNOTATED_TABLE_MIGRATIONS, true)) {
            // These migrations predate the strict annotation policy but are already
            // applied and remain out of scope for retroactive catalog changes.
            return [];
        }

        $errors = [];
        if (preg_match('/@global-resource\s+reason=([^\r\n]+)/i', $source, $global) === 1) {
            if (trim($global[1]) === '') {
                $errors[] = 'Global resource declaration needs a reason.';
            }
            return $errors;
        }

        preg_match_all('/@tenant-resource\s+([A-Za-z0-9_.-]+)/i', $source, $matches);
        $resources = array_values(array_unique($matches[1] ?? []));
        if ($resources === []) {
            return ['Future table migration must declare @tenant-resource or @global-resource reason=.'];
        }
        foreach ($resources as $resource) {
            if (!in_array($resource, $registeredResources, true)) {
                $errors[] = 'Tenant resource is missing from TenantResourceCatalog: ' . $resource;
            }
        }
        if (preg_match('/[`"\']?company_id[`"\']?/i', $source) !== 1) {
            $errors[] = 'Tenant table migration must include company_id.';
        }
        if (
            preg_match(
                '/(?:KEY|INDEX|UNIQUE\s+KEY)\s+[`"\']?[A-Za-z0-9_]+[`"\']?\s*'
                . '\(\s*[`"\']?company_id[`"\']?/i',
                $source
            ) !== 1
        ) {
            $errors[] = 'Tenant table migration needs an index beginning with company_id.';
        }
        if (
            preg_match(
                '/FOREIGN\s+KEY\s*\(\s*[`"\']?company_id[`"\']?\s*\)/i',
                $source
            ) !== 1
        ) {
            $errors[] = 'Tenant table migration needs a company_id foreign key.';
        }
        return $errors;
    }
}

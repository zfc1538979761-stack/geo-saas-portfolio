<?php

declare(strict_types=1);

namespace app\common\tenant;

final class TenantNamespace
{
    public static function cacheKey(TenantContext $context, array $segments): string
    {
        return 'tenant:' . self::companyId($context) . ':' . implode(':', self::segments($segments));
    }

    public static function fileKey(TenantContext $context, array $segments): string
    {
        return 'tenants/' . self::companyId($context) . '/' . implode('/', self::segments($segments));
    }

    public static function exportKey(TenantContext $context, string $jobId): string
    {
        return self::cacheKey($context, ['export', $jobId]);
    }

    private static function companyId(TenantContext $context): int
    {
        $companyId = $context->companyId();
        if ($companyId === null || $companyId < 1 || $context->canBypass()) {
            throw new TenantException('A concrete tenant is required for namespaced data.');
        }
        return $companyId;
    }

    private static function segments(array $segments): array
    {
        if ($segments === []) {
            throw new TenantException('Tenant namespace segments are required.');
        }
        return array_map(static function ($segment): string {
            $value = trim((string) $segment);
            if (
                $value === ''
                || $value === '.'
                || $value === '..'
                || strpos($value, '/') !== false
                || strpos($value, '\\') !== false
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            ) {
                throw new TenantException('Tenant namespace segment is invalid.');
            }
            return $value;
        }, $segments);
    }
}

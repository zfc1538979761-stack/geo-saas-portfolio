<?php

declare(strict_types=1);

namespace app\common\company;

use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantCompanyGateway;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantException;
use app\common\tenant\TenantResourceCatalog;
use app\common\tenant\TenantResourceGuard;
use PDO;
use think\Config;
use think\Db;

final class CompanyProfileServiceFactory
{
    public static function create(): CompanyProfileService
    {
        $connection = Db::connect();
        $connection->query('SELECT 1');
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO) {
            throw new TenantException('Database connection is unavailable.');
        }
        $prefix = (string) Config::get('database.prefix');
        $registry = TenantResourceCatalog::registry($prefix);
        TenantResourceGuard::assertCompliant(CompanyProfileResource::class, $registry);
        $audit = new PdoAuditLogger($pdo, $prefix);
        $companyConfig = (array) Config::get('geo_company');

        return new CompanyProfileService(
            $pdo,
            new TenantDataGateway($pdo, $registry, $audit),
            new TenantCompanyGateway($pdo, $prefix),
            new OpenSslContactCipher((string) ($companyConfig['contact_encryption_key'] ?? '')),
            $audit
        );
    }

    private function __construct()
    {
    }
}

<?php

declare(strict_types=1);

namespace app\common\company\intelligence;

use app\common\ai\AiProvider;
use app\common\ai\AiProviderFactory;
use app\common\ai\task\AiTaskRuntimeFactory;
use app\common\company\CompanyProfileService;
use app\common\company\OpenSslContactCipher;
use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantCompanyGateway;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantException;
use app\common\tenant\TenantResourceCatalog;
use PDO;
use think\Config;
use think\Db;

final class CompanyIntelligenceServiceFactory
{
    public static function create(): CompanyIntelligenceService
    {
        $connection = Db::connect();
        $connection->query('SELECT 1');
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO) { throw new TenantException('Database connection is unavailable.'); }
        $prefix = (string) Config::get('database.prefix');
        $audit = new PdoAuditLogger($pdo, $prefix);
        $gateway = new TenantDataGateway($pdo, TenantResourceCatalog::registry($prefix), $audit);
        $companyConfig = (array) Config::get('geo_company');
        $profiles = new CompanyProfileService(
            $pdo,
            $gateway,
            new TenantCompanyGateway($pdo, $prefix),
            new OpenSslContactCipher((string) ($companyConfig['contact_encryption_key'] ?? '')),
            $audit
        );
        return new CompanyIntelligenceService($pdo, $gateway, $profiles, $audit, $prefix);
    }

    public static function discoveryProvider(): CompanyDiscoveryProvider
    {
        $config = (array) Config::get('geo_company_discovery');
        if ($config === []) {
            $config = require APP_PATH . 'extra' . DIRECTORY_SEPARATOR . 'geo_company_discovery.php';
        }
        return CompanyDiscoveryProviderFactory::make($config, null, self::aiProvider());
    }

    public static function aiProvider(): AiProvider
    {
        return AiProviderFactory::create(AiTaskRuntimeFactory::config());
    }

    private function __construct() {}
}

<?php

declare(strict_types=1);

namespace app\common\content;

use app\common\content\foundation\ContentAssetPolicy;
use app\common\content\foundation\EnterpriseFileValidator;
use app\common\content\storage\StorageAdapterFactory;
use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantException;
use app\common\tenant\TenantResourceCatalog;
use PDO;
use think\Config;
use think\Db;

final class T06ContentServiceFactory
{
    public static function knowledge(): KnowledgeItemService
    {
        [$pdo, $gateway, $audit, $config] = self::dependencies();
        $environment = strtolower(trim((string) getenv('GEO_APP_ENV')));
        $storage = StorageAdapterFactory::create($environment, (array) ($config['storage'] ?? []));
        return new KnowledgeItemService(
            $pdo,
            $gateway,
            $audit,
            $storage,
            new EnterpriseFileValidator(new ContentAssetPolicy((array) $config['files'])),
            $config
        );
    }

    public static function prompts(): PromptTemplateService
    {
        [$pdo, $gateway, $audit, $config] = self::dependencies();
        return new PromptTemplateService($pdo, $gateway, $audit, $config);
    }

    public static function articles(): ArticleService
    {
        [$pdo, $gateway, $audit, $config] = self::dependencies();
        return new ArticleService($pdo, $gateway, $audit, $config);
    }

    private static function dependencies(): array
    {
        $connection = Db::connect();
        $connection->query('SELECT 1');
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO) { throw new TenantException('Database connection is unavailable.'); }
        $prefix = (string) Config::get('database.prefix');
        $config = (array) Config::get('geo_content');
        if ($config === []) {
            $config = require APP_PATH . 'extra' . DIRECTORY_SEPARATOR . 'geo_content.php';
        }
        $audit = new PdoAuditLogger($pdo, $prefix);
        return [$pdo, new TenantDataGateway($pdo, TenantResourceCatalog::registry($prefix), $audit), $audit, $config];
    }

    private function __construct() {}
}

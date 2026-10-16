<?php

declare(strict_types=1);

namespace app\common\geo;

use app\common\ai\AiProvider;
use app\common\ai\AiProviderFactory;
use app\common\ai\task\AiTaskRuntimeFactory;
use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantException;
use app\common\tenant\TenantResourceCatalog;
use PDO;
use think\Config;
use think\Db;

final class GeoQuestionRuntimeFactory
{
    public static function service(): QuestionGenerationService
    {
        $connection = Db::connect();
        $connection->query('SELECT 1');
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO) { throw new TenantException('Database connection is unavailable.'); }
        $prefix = (string) Config::get('database.prefix');
        $audit = new PdoAuditLogger($pdo, $prefix);
        return new QuestionGenerationService(
            $pdo,
            new TenantDataGateway($pdo, TenantResourceCatalog::registry($prefix), $audit),
            $audit
        );
    }

    public static function provider(): AiProvider
    {
        return AiProviderFactory::create(AiTaskRuntimeFactory::config());
    }

    private function __construct() {}
}

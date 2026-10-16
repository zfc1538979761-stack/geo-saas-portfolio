<?php

declare(strict_types=1);

namespace app\common\geo;

use app\common\ai\AiProvider;
use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantException;
use app\common\tenant\TenantResourceCatalog;
use PDO;
use think\Config;
use think\Db;

final class GeoAnswerRuntimeFactory
{
    public static function service(): TargetAnswerService
    {
        $connection = Db::connect();
        $connection->query('SELECT 1');
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO) { throw new TenantException('Database connection is unavailable.'); }
        $prefix = (string) Config::get('database.prefix');
        $audit = new PdoAuditLogger($pdo, $prefix);
        return new TargetAnswerService(
            $pdo,
            new TenantDataGateway($pdo, TenantResourceCatalog::registry($prefix), $audit),
            $audit
        );
    }

    public static function provider(): AiProvider
    {
        return GeoQuestionRuntimeFactory::provider();
    }

    private function __construct() {}
}

<?php

declare(strict_types=1);

namespace app\common\content;

use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantException;
use app\common\tenant\TenantResourceCatalog;
use app\common\tenant\TenantResourceGuard;
use PDO;
use think\Config;
use think\Db;

final class KeywordQuestionServiceFactory
{
    public static function create(): KeywordQuestionService
    {
        $connection = Db::connect();
        $connection->query('SELECT 1');
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO) {
            throw new TenantException('Database connection is unavailable.');
        }
        $prefix = (string) Config::get('database.prefix');
        $registry = TenantResourceCatalog::registry($prefix);
        TenantResourceGuard::assertCompliant(KeywordResource::class, $registry);
        TenantResourceGuard::assertCompliant(QuestionResource::class, $registry);
        $audit = new PdoAuditLogger($pdo, $prefix);
        return new KeywordQuestionService($pdo, new TenantDataGateway($pdo, $registry, $audit), $audit);
    }

    private function __construct()
    {
    }
}

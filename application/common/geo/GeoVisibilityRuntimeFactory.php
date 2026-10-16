<?php

declare(strict_types=1);

namespace app\common\geo;

use app\common\ai\AiProvider;
use app\common\ai\AiProviderFactory;
use app\common\ai\task\AiTaskRuntimeFactory;
use app\common\geo\visibility\VisibilityProvider;
use app\common\geo\visibility\VisibilityProviderFactory;
use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantContextResolver;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantException;
use app\common\tenant\TenantResourceCatalog;
use PDO;
use think\Config;
use think\Db;

final class GeoVisibilityRuntimeFactory
{
    public static function service(): VisibilityService
    {
        [$pdo, $gateway, $audit, $prefix] = self::dependencies();
        return new VisibilityService(
            $pdo, $gateway, $audit, new VisibilityClassifier(), null,
            new VisibilityPublicationTimeline($pdo, $prefix)
        );
    }

    public static function provider(): VisibilityProvider
    {
        return VisibilityProviderFactory::make(self::config());
    }

    public static function trend(): VisibilityTrendService
    {
        [, $gateway, $audit] = self::dependencies();
        return new VisibilityTrendService($gateway, $audit);
    }

    public static function suggestions(): OptimizationSuggestionService
    {
        [, $gateway, $audit] = self::dependencies();
        return new OptimizationSuggestionService($gateway, $audit);
    }

    public static function optimizationProvider(): AiProvider
    {
        return AiProviderFactory::create(AiTaskRuntimeFactory::config());
    }

    public static function scheduler(): VisibilityScheduler
    {
        [$pdo, $gateway, $audit, $prefix, $config] = self::dependencies();
        $timeline = new VisibilityPublicationTimeline($pdo, $prefix);
        return new VisibilityScheduler(
            $pdo, $gateway, $audit, new ThinkQueueVisibilityTaskDispatcher(), AiTaskRuntimeFactory::envelope(),
            $prefix, null, (string) ($config['queue'] ?? 'geo_visibility'), $timeline
        );
    }

    public static function runner(?VisibilityProvider $visibilityProvider = null, ?AiProvider $optimizationProvider = null): VisibilityJobRunner
    {
        [$pdo, $gateway, $audit, $prefix, $config] = self::dependencies();
        $visibilityProvider = $visibilityProvider ?: VisibilityProviderFactory::make($config);
        $optimizationProvider = $optimizationProvider ?: AiProviderFactory::create(AiTaskRuntimeFactory::config());
        $visibility = new VisibilityService(
            $pdo, $gateway, $audit, new VisibilityClassifier(), null,
            new VisibilityPublicationTimeline($pdo, $prefix)
        );
        return new VisibilityJobRunner(
            $pdo, $gateway, $visibility, new VisibilityTrendService($gateway, $audit),
            new OptimizationSuggestionService($gateway, $audit), $visibilityProvider,
            $optimizationProvider, $audit, $prefix, $config
        );
    }

    public static function contextForPlan(array $plan): TenantContext
    {
        [$pdo, , , $prefix] = self::dependencies();
        $companyId = (int) ($plan['company_id'] ?? 0);
        $actorId = (int) ($plan['created_by'] ?? 0);
        if ($companyId < 1 || $actorId < 1) { throw new TenantException('Visibility plan tenant identity is invalid.'); }
        $statement = $pdo->prepare(
            'SELECT u.`id` FROM `' . $prefix . 'user` u INNER JOIN `' . $prefix . 'companies` c ON c.`id`=u.`company_id`'
            . ' WHERE u.`id`=? AND u.`company_id`=? AND u.`status`=\'normal\' AND c.`status`=\'normal\' LIMIT 1'
        );
        $statement->execute([$actorId, $companyId]);
        if ((int) $statement->fetchColumn() !== $actorId) { throw new TenantException('Visibility plan tenant identity is no longer active.'); }
        return TenantContextResolver::fromTrustedIdentity($actorId, $companyId, 'trusted_visibility_scheduler');
    }

    public static function config(): array
    {
        $config = (array) Config::get('geo_visibility');
        return $config === [] ? require APP_PATH . 'extra' . DIRECTORY_SEPARATOR . 'geo_visibility.php' : $config;
    }

    private static function dependencies(): array
    {
        $connection = Db::connect(); $connection->query('SELECT 1'); $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO) { throw new TenantException('Database connection is unavailable.'); }
        $prefix = (string) Config::get('database.prefix');
        if (preg_match('/^[A-Za-z0-9_]*$/D', $prefix) !== 1) { throw new TenantException('Database prefix is invalid.'); }
        $audit = new PdoAuditLogger($pdo, $prefix);
        return [$pdo, new TenantDataGateway($pdo, TenantResourceCatalog::registry($prefix), $audit), $audit, $prefix, self::config()];
    }

    private function __construct() {}
}

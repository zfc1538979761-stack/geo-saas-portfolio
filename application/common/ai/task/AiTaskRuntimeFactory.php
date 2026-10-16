<?php

declare(strict_types=1);

namespace app\common\ai\task;

use app\common\ai\AiCostCalculator;
use app\common\ai\AiProviderFactory;
use app\common\ai\ArticleOutputParser;
use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantException;
use app\common\tenant\TenantResourceCatalog;
use app\common\tenant\TenantTaskEnvelope;
use PDO;
use think\Config;
use think\Db;

final class AiTaskRuntimeFactory
{
    public static function service(): AiGenerationService
    {
        [$pdo, $gateway, $audit, $prefix, $ai] = self::dependencies();
        return new AiGenerationService(
            $pdo, $gateway, $audit, new ThinkQueueAiTaskDispatcher(), self::envelope(), $prefix, $ai
        );
    }

    public static function runner(): AiGenerationRunner
    {
        [$pdo, $gateway, $audit, $prefix, $ai] = self::dependencies();
        $content = (array) Config::get('geo_content');
        if ($content === []) { $content = require APP_PATH . 'extra' . DIRECTORY_SEPARATOR . 'geo_content.php'; }
        $pricing = (array) ($ai['pricing'] ?? []);
        return new AiGenerationRunner(
            $pdo, $gateway, $audit, new AiArticleWriter($pdo, $gateway, $audit), new AiPromptAssembler(),
            new ArticleOutputParser((int) $content['text']['title_max_characters'], (int) $content['text']['article_max_characters']),
            new AiCostCalculator(
                self::nullable($pricing['input_per_million'] ?? null),
                self::nullable($pricing['output_per_million'] ?? null),
                (string) ($pricing['currency'] ?? 'CNY')
            ),
            static function (string $providerCode) use ($ai) {
                if ($providerCode !== (string) ($ai['provider'] ?? '')) { throw new TenantException('AI job Provider no longer matches runtime configuration.'); }
                return AiProviderFactory::create($ai);
            },
            $prefix,
            $ai
        );
    }

    public static function envelope(): TenantTaskEnvelope
    {
        $key = getenv('GEO_APP_TOKEN_KEY');
        if ($key === false || trim($key) === '') { $key = (string) Config::get('token.key'); }
        return new TenantTaskEnvelope((string) $key);
    }

    public static function config(): array
    {
        $config = (array) Config::get('geo_ai');
        return $config === [] ? require APP_PATH . 'extra' . DIRECTORY_SEPARATOR . 'geo_ai.php' : $config;
    }

    private static function dependencies(): array
    {
        $connection = Db::connect(); $connection->query('SELECT 1'); $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO) { throw new TenantException('Database connection is unavailable.'); }
        $prefix = (string) Config::get('database.prefix'); $audit = new PdoAuditLogger($pdo, $prefix);
        return [$pdo, new TenantDataGateway($pdo, TenantResourceCatalog::registry($prefix), $audit), $audit, $prefix, self::config()];
    }

    private static function nullable($value): ?string
    {
        $value = trim((string) $value); return $value === '' ? null : $value;
    }

    private function __construct() {}
}

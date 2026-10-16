<?php

declare(strict_types=1);

if (getenv('GEO_T07_SERVICE_TEST_ENABLE') !== '1') { fwrite(STDOUT, "SKIP: T07 service test is not enabled\n"); exit(0); }
if (getenv('GEO_APP_ENV') !== 'testing' || getenv('GEO_T07_SERVICE_TEST_ALLOW_SHARED_DATABASE') !== '1' || getenv('GEO_T07_SERVICE_TEST_PREFIX') !== 't07_service_') {
    fwrite(STDERR, "FAIL: T07 service test safety gate rejected the environment\n"); exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\ai\AiCostCalculator;
use app\common\ai\AiGenerationRequest;
use app\common\ai\AiGenerationResult;
use app\common\ai\AiProvider;
use app\common\ai\ArticleOutputParser;
use app\common\ai\exception\AiProviderException;
use app\common\ai\task\AiArticleWriter;
use app\common\ai\task\AiGenerationRunner;
use app\common\ai\task\AiGenerationService;
use app\common\ai\task\AiPromptAssembler;
use app\common\ai\task\AiTaskDispatcher;
use app\common\ai\task\AiTaskException;
use app\common\job\RetryableJobException;
use app\common\migration\MigrationManager;
use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantResourceCatalog;
use app\common\tenant\TenantTaskEnvelope;

final class T07ServiceFailure extends RuntimeException {}
final class T07RecordingDispatcher implements AiTaskDispatcher
{
    public array $items = [];
    public function dispatch(array $envelope, string $queue): void { $this->items[] = ['envelope' => $envelope, 'queue' => $queue]; }
}
final class T07SequenceProvider implements AiProvider
{
    public int $calls = 0;
    private array $sequence;
    public function __construct(array $sequence) { $this->sequence = $sequence; }
    public function providerCode(): string { return 'mock'; }
    public function generate(AiGenerationRequest $request): AiGenerationResult
    {
        $item = $this->sequence[min($this->calls, count($this->sequence) - 1)]; $this->calls++;
        if ($item instanceof Throwable) { throw $item; }
        return new AiGenerationResult((string) $item['raw'], 'mock', (string) ($item['model'] ?? 'mock-sequence'), (string) ($item['request_id'] ?? 'req-safe'), $item['input_tokens'] ?? null, $item['output_tokens'] ?? null, (int) ($item['latency_ms'] ?? 4));
    }
}

function t07ServiceAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new T07ServiceFailure($message); }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}
function t07ServiceReject(callable $operation, int $status, string $message): void
{
    try { $operation(); } catch (AiTaskException $exception) { t07ServiceAssert($exception->httpStatus() === $status, $message); return; }
    throw new T07ServiceFailure($message);
}

$host = (string) getenv('GEO_DATABASE_HOSTNAME'); $port = (int) getenv('GEO_DATABASE_HOSTPORT');
$database = (string) getenv('GEO_DATABASE_DATABASE'); $user = (string) getenv('GEO_DATABASE_USERNAME');
$password = (string) getenv('GEO_DATABASE_PASSWORD');
if ($host !== '127.0.0.1' || $port < 1 || $port === 3306 || $database !== getenv('GEO_T07_SERVICE_TEST_DATABASE') || preg_match('/_test$/D', $database) !== 1) {
    fwrite(STDERR, "FAIL: T07 service test requires an isolated non-3306 test database\n"); exit(1);
}
$prefix = 't07_service_';
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
$failure = null;

try {
    $auditExists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $auditExists->execute([$prefix . 'audit_logs']);
    if ((int) $auditExists->fetchColumn() > 0) { $pdo->exec('DELETE FROM `' . $prefix . 'audit_logs`'); }
    $applied = $manager->refresh();
    $status = $manager->status();
    t07ServiceAssert($applied !== [] && !in_array('pending', $status, true), 'service fixture applies all discovered migrations');
    t07ServiceAssert(($status['202608040007_create_ai_generation'] ?? '') === 'applied', 'T07 AI migration is applied in service fixture');
    $now = 1785801600;
    $company = $pdo->prepare('INSERT INTO `' . $prefix . 'companies` (`name`,`status`,`createtime`,`updatetime`) VALUES (?,?,?,?)');
    $company->execute(['Company A', 'normal', $now, $now]); $companyA = (int) $pdo->lastInsertId();
    $company->execute(['Company B', 'normal', $now, $now]); $companyB = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO `' . $prefix . 'company_profiles` (`company_id`,`brand`,`industry`,`products_json`,`website`,`introduction`,`contacts_ciphertext`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$companyA, 'Safe Brand', 'Software', '["GEO service"]', 'https://example.invalid', 'Non-sensitive fixture.', 'encrypted-contact-must-not-leak', $now, $now]);
    $contextA = TenantContext::enterprise(101, $companyA, 't07_test'); $contextB = TenantContext::enterprise(202, $companyB, 't07_test');
    $keywordInsert = $pdo->prepare('INSERT INTO `' . $prefix . 'keywords` (`company_id`,`keyword`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?)');
    $questionInsert = $pdo->prepare('INSERT INTO `' . $prefix . 'questions` (`company_id`,`keyword_id`,`text`,`text_hash`,`source`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $keywordInsert->execute([$companyA, 'GEO test', 'normal', 101, 101, $now, $now]); $keywordA = (int) $pdo->lastInsertId();
    $questionInsert->execute([$companyA, $keywordA, 'What is the safe test?', hash('sha256', 'qa', true), 'manual', 'normal', 101, 101, $now, $now]); $questionA = (int) $pdo->lastInsertId();
    $keywordInsert->execute([$companyB, 'Other tenant', 'normal', 202, 202, $now, $now]); $keywordB = (int) $pdo->lastInsertId();
    $questionInsert->execute([$companyB, $keywordB, 'Other question', hash('sha256', 'qb', true), 'manual', 'normal', 202, 202, $now, $now]);
    $promptInsert = $pdo->prepare('INSERT INTO `' . $prefix . 'prompt_templates` (`company_id`,`logical_key`,`name`,`scene`,`version_no`,`content`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $promptInsert->execute([$companyA, str_repeat('a', 32), 'Exact prompt', 'article', 1, 'Write a concise factual article.', 'normal', 101, 101, $now, $now]); $promptA = (int) $pdo->lastInsertId();
    $knowledgeInsert = $pdo->prepare('INSERT INTO `' . $prefix . 'knowledge_items` (`company_id`,`type`,`title`,`content`,`oss_file_index_id`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $knowledgeInsert->execute([$companyA, 'text', 'Text fact', 'Only this text is usable.', null, 'normal', 101, 101, $now, $now]); $textA = (int) $pdo->lastInsertId();
    $knowledgeInsert->execute([$companyA, 'image', 'Deferred file', null, null, 'normal', 101, 101, $now, $now]); $imageA = (int) $pdo->lastInsertId();

    $audit = new PdoAuditLogger($pdo, $prefix); $gateway = new TenantDataGateway($pdo, TenantResourceCatalog::registry($prefix), $audit);
    $clockValue = $now; $clock = static function () use (&$clockValue): int { return $clockValue; };
    $dispatcher = new T07RecordingDispatcher(); $envelopes = new TenantTaskEnvelope(str_repeat('k', 64), $clock);
    $config = ['provider' => 'mock', 'environment' => 'testing', 'queue' => 'geo_ai_generation', 'max_attempts' => 3, 'retry_delays' => [2, 3], 'max_output_tokens' => 256, 'pricing' => ['version' => 'test-v1']];
    $service = new AiGenerationService($pdo, $gateway, $audit, $dispatcher, $envelopes, $prefix, $config, $clock);
    $baseInput = ['keyword_id' => $keywordA, 'question_id' => $questionA, 'prompt_template_id' => $promptA, 'knowledge_item_ids' => [$textA]];

    $task = $service->create($baseInput, 'click-1', $contextA);
    t07ServiceAssert($task['status'] === 'queued' && count($dispatcher->items) === 1, 'web create returns queued task after dispatch only');
    $snapshot = json_decode((string) $pdo->query('SELECT `input_snapshot_json` FROM `' . $prefix . 'geo_async_jobs` WHERE `id`=' . (int) $task['id'])->fetchColumn(), true, 64, JSON_THROW_ON_ERROR);
    t07ServiceAssert($snapshot['prompt']['id'] === $promptA && $snapshot['prompt']['version_no'] === 1, 'exact prompt version is snapshotted');
    t07ServiceAssert(count($snapshot['knowledge']) === 1 && $snapshot['knowledge'][0]['content_sha256'] === hash('sha256', 'Only this text is usable.'), 'text knowledge content and hash are snapshotted');
    t07ServiceAssert(strpos(json_encode($snapshot), 'encrypted-contact') === false, 'company contacts are excluded from AI snapshot');
    t07ServiceReject(static fn() => $service->create(array_merge($baseInput, ['knowledge_item_ids' => [$imageA]]), 'file-denied', $contextA), 404, 'file knowledge is rejected while T06-OSS is deferred');
    $duplicate = $service->create($baseInput, 'click-1', $contextA);
    t07ServiceAssert($duplicate['id'] === $task['id'] && count($dispatcher->items) === 1, 'duplicate click returns one database task and one queue dispatch');
    t07ServiceReject(static fn() => $service->create(array_merge($baseInput, ['knowledge_item_ids' => []]), 'click-1', $contextA), 409, 'one idempotency key cannot alias a different generation request');
    t07ServiceReject(static fn() => $service->detail($task['id'], $contextB), 404, 'B cannot read A task');
    t07ServiceReject(static fn() => $service->cancel($task['id'], $contextB), 404, 'B cannot cancel A task');

    $cancelled = $service->create($baseInput, 'cancel-1', $contextA); $service->cancel($cancelled['id'], $contextA);
    $cancelProvider = new T07SequenceProvider([['raw' => '{"title":"x","content":"y"}']]);
    $makeRunner = static function (AiProvider $provider, AiCostCalculator $costs) use ($pdo, $gateway, $audit, $prefix, $config, $clock): AiGenerationRunner {
        return new AiGenerationRunner($pdo, $gateway, $audit, new AiArticleWriter($pdo, $gateway, $audit, $clock), new AiPromptAssembler(), new ArticleOutputParser(191, 100000), $costs, static fn() => $provider, $prefix, $config, $clock);
    };
    $makeRunner($cancelProvider, new AiCostCalculator(null, null, 'CNY'))->run($cancelled['id'], $contextA);
    t07ServiceAssert($cancelProvider->calls === 0 && $service->detail($cancelled['id'], $contextA)['status'] === 'cancelled', 'cancelled queued task never calls Provider');

    $successProvider = new T07SequenceProvider([['raw' => '{"title":"AI title","content":"AI body"}', 'input_tokens' => 100, 'output_tokens' => 50, 'latency_ms' => 12, 'request_id' => 'req-123']]);
    $runner = $makeRunner($successProvider, new AiCostCalculator(null, null, 'CNY')); $runner->run($task['id'], $contextA);
    $success = $service->result($task['id'], $contextA);
    t07ServiceAssert($success['task']['status'] === 'succeeded' && $success['version']['source'] === 'ai' && $success['version']['version_no'] === 1, 'successful Worker creates an AI article and version');
    $call = $pdo->query('SELECT * FROM `' . $prefix . 'ai_calls` WHERE `job_id`=' . (int) $task['id'])->fetch();
    t07ServiceAssert((int) $call['input_tokens'] === 100 && (int) $call['output_tokens'] === 50 && (int) $call['latency_ms'] === 12 && $call['provider_request_id'] === 'req-123', 'tokens latency and Provider request ID are recorded');
    t07ServiceAssert($call['cost_amount'] === null && $call['currency'] === null, 'unconfirmed pricing records NULL cost instead of a guess');
    $runner->run($task['id'], $contextA);
    t07ServiceAssert($successProvider->calls === 1 && (int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'article_versions` WHERE `article_id`=' . (int) $success['article']['id'])->fetchColumn() === 1, 'duplicate queue delivery does not call or version twice');

    $targetInput = array_merge($baseInput, ['article_id' => $success['article']['id'], 'current_version_id' => $success['version']['id']]);
    $invalidTask = $service->create($targetInput, 'invalid-json', $contextA); $invalidProvider = new T07SequenceProvider([['raw' => 'not json']]);
    $makeRunner($invalidProvider, new AiCostCalculator(null, null, 'CNY'))->run($invalidTask['id'], $contextA);
    t07ServiceAssert($service->detail($invalidTask['id'], $contextA)['status'] === 'failed', 'invalid JSON fails without retry');
    t07ServiceAssert((int) $pdo->query('SELECT `current_version_id` FROM `' . $prefix . 'articles` WHERE `id`=' . (int) $success['article']['id'])->fetchColumn() === $success['version']['id'], 'invalid output does not overwrite current article version');

    $retryTask = $service->create($targetInput, 'retry-429', $contextA);
    $retryProvider = new T07SequenceProvider([new AiProviderException('raw provider detail', 'rate_limit', true, 429, 'retry-req'), ['raw' => '{"title":"AI title v2","content":"AI body v2"}']]);
    $retryRunner = $makeRunner($retryProvider, new AiCostCalculator(null, null, 'CNY'));
    try { $retryRunner->run($retryTask['id'], $contextA); throw new T07ServiceFailure('429 should request queue retry'); } catch (RetryableJobException $expected) {}
    t07ServiceAssert($service->detail($retryTask['id'], $contextA)['status'] === 'queued' && $retryProvider->calls === 1, '429 is requeued with bounded retry');
    $clockValue += 2; $retryRunner->run($retryTask['id'], $contextA); $retryResult = $service->result($retryTask['id'], $contextA);
    t07ServiceAssert($retryResult['version']['version_no'] === 2 && $retryProvider->calls === 2, 'retry succeeds once and appends a new article version');

    $authTask = $service->create(array_merge($baseInput, ['article_id' => $success['article']['id'], 'current_version_id' => $retryResult['version']['id']]), 'auth-401', $contextA);
    $authProvider = new T07SequenceProvider([new AiProviderException('secret response', 'authentication', false, 401)]);
    $makeRunner($authProvider, new AiCostCalculator(null, null, 'CNY'))->run($authTask['id'], $contextA);
    $authResult = $service->detail($authTask['id'], $contextA);
    t07ServiceAssert($authResult['status'] === 'failed' && $authResult['attempts'] === 1 && $authProvider->calls === 1, '401 fails immediately without retry');
    t07ServiceAssert(strpos(json_encode($authResult), 'secret response') === false, 'task response contains only a safe classified error');
    t07ServiceReject(static fn() => $service->retry($authTask['id'], $contextB), 404, 'B cannot retry A task');

    $retriedAuth = $service->retry($authTask['id'], $contextA);
    t07ServiceAssert($retriedAuth['status'] === 'queued' && $retriedAuth['attempts'] === 1 && $retriedAuth['max_attempts'] === 4, 'safe manual retry preserves attempt history and adds one bounded budget');
    $recoveryProvider = new T07SequenceProvider([['raw' => '{"title":"Recovered title","content":"Recovered body"}']]);
    $makeRunner($recoveryProvider, new AiCostCalculator(null, null, 'CNY'))->run($authTask['id'], $contextA);
    $recoveredResult = $service->result($authTask['id'], $contextA);
    t07ServiceAssert($recoveredResult['version']['version_no'] === 3 && $recoveryProvider->calls === 1, 'manual retry uses the next unique attempt and succeeds safely');

    $pricedTask = $service->create(array_merge($baseInput, ['article_id' => $success['article']['id'], 'current_version_id' => $recoveredResult['version']['id']]), 'priced', $contextA);
    $pricedProvider = new T07SequenceProvider([['raw' => '{"title":"AI title v3","content":"AI body v3"}', 'input_tokens' => 1000000, 'output_tokens' => 500000]]);
    $makeRunner($pricedProvider, new AiCostCalculator('2.000000', '4.000000', 'CNY'))->run($pricedTask['id'], $contextA);
    $pricedCall = $pdo->query('SELECT `cost_amount`,`currency`,`pricing_version` FROM `' . $prefix . 'ai_calls` WHERE `job_id`=' . (int) $pricedTask['id'])->fetch();
    t07ServiceAssert($pricedCall['cost_amount'] === '4.000000' && $pricedCall['currency'] === 'CNY' && $pricedCall['pricing_version'] === 'test-v1', 'confirmed pricing config calculates exact cost');
    t07ServiceAssert((int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'ai_calls` WHERE `company_id`=' . $companyB)->fetchColumn() === 0, 'AI call records remain tenant scoped');
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    try {
        $auditExists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $auditExists->execute([$prefix . 'audit_logs']);
        if ((int) $auditExists->fetchColumn() > 0) { $pdo->exec('DELETE FROM `' . $prefix . 'audit_logs`'); }
        $manager->refresh(0);
    } catch (Throwable $cleanup) { if ($failure === null) { $failure = $cleanup; } }
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . get_class($failure) . ' file=' . basename($failure->getFile()) . ' line=' . $failure->getLine() . ' message=' . $failure->getMessage() . PHP_EOL); exit(1);
}
fwrite(STDOUT, "RESULT: T07 task service, tenant, idempotency, retry, provenance, and cost tests passed\n");

<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\ai\AiGenerationRequest;
use app\common\ai\AiGenerationResult;
use app\common\ai\AiProvider;
use app\common\ai\AiCostCalculator;
use app\common\ai\ArticleOutputParser;
use app\common\ai\task\AiArticleWriter;
use app\common\ai\task\AiPromptAssembler;
use app\common\ai\task\AiGenerationService;
use app\common\ai\task\AiGenerationRunner;
use app\common\ai\task\AiTaskDispatcher;
use app\common\geo\GeoArticleContextService;
use app\common\geo\GeoException;
use app\common\geo\QuestionGenerationService;
use app\common\geo\TargetAnswerService;
use app\common\migration\MigrationManager;
use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantResourceCatalog;
use app\common\tenant\TenantTaskEnvelope;
use think\Env;

final class T19CoreFailure extends RuntimeException {}
final class T19CoreDispatcher implements AiTaskDispatcher
{
    public array $items = [];
    public function dispatch(array $envelope, string $queue): void { $this->items[] = [$envelope, $queue]; }
}
final class T19SequenceAiProvider implements AiProvider
{
    private array $items;
    private int $index = 0;
    public function __construct(array $items) { $this->items = $items; }
    public function providerCode(): string { return 'mock'; }
    public function generate(AiGenerationRequest $request): AiGenerationResult
    {
        $raw = (string) $this->items[min($this->index, count($this->items) - 1)];
        $this->index++;
        return new AiGenerationResult($raw, 'mock', 'mock-t19', 'req-' . $this->index, 10, 20, 3);
    }
}
final class T19SourceContractAiProvider implements AiProvider
{
    public function providerCode(): string { return 'mock'; }
    public function generate(AiGenerationRequest $request): AiGenerationResult
    {
        $input = json_decode($request->userPrompt(), true, 32, JSON_THROW_ON_ERROR);
        $sources = is_array($input['sources'] ?? null) ? $input['sources'] : [];
        $requirements = is_array($input['response_requirements'] ?? null) ? $input['response_requirements'] : [];
        $profile = null;
        $product = null;
        $knowledge = null;
        foreach ($sources as $source) {
            if (!is_array($source)) { continue; }
            if (($source['source_type'] ?? null) === 'profile' && ($source['source_id'] ?? null) === 'introduction') {
                $profile = $source;
            }
            if (($source['source_type'] ?? null) === 'profile' && ($source['source_id'] ?? null) === 'products') {
                $product = $source;
            }
            if (($source['source_type'] ?? null) === 'knowledge') {
                $knowledge = $source;
            }
        }
        $pointRange = is_array($requirements['supported_information_points'] ?? null)
            ? $requirements['supported_information_points']
            : [];
        $qualityContract = ($requirements['direct_answer_first'] ?? null) === true
            && ($requirements['style'] ?? null) === 'natural_recommendation'
            && (int) ($pointRange['min'] ?? 0) === 2
            && (int) ($pointRange['max'] ?? 0) === 4
            && ($requirements['insufficient_evidence'] ?? null) === 'state_limits_conservatively';
        if (!is_array($profile) || !is_array($product) || !is_array($knowledge) || !$qualityContract) {
            $raw = '{"answer":"Ambiguous source contract.","recommendation_reason":"","facts":[{"claim":"Ambiguous source contract.","source_type":"profile","source_id":"company_profile","evidence_quote":"Acme GEO"}]}';
        } else {
            $raw = json_encode([
                'answer' => 'Yes. Acme GEO serves small businesses, provides a GEO service, and supports auditable answer evidence. The supplied sources do not provide pricing or case-study details, so those should be confirmed separately.',
                'recommendation_reason' => 'Three directly relevant points are supported by the supplied enterprise sources.',
                'facts' => [
                    ['claim' => 'Acme GEO serves small businesses.', 'source_type' => 'profile', 'source_id' => 'introduction', 'evidence_quote' => 'Acme GEO serves small businesses.'],
                    ['claim' => 'Acme GEO provides a GEO service.', 'source_type' => 'profile', 'source_id' => 'products', 'evidence_quote' => '["GEO service"]'],
                    ['claim' => 'It supports auditable answer evidence.', 'source_type' => 'knowledge', 'source_id' => (int) $knowledge['source_id'], 'evidence_quote' => 'supports auditable answer evidence'],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        return new AiGenerationResult($raw, 'mock', 'mock-t19', 'source-contract-1', 10, 20, 3);
    }
}

function t19CoreAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new T19CoreFailure($message); }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}
function t19CoreReject(callable $operation, int $status, string $message): void
{
    try { $operation(); } catch (GeoException $exception) { t19CoreAssert($exception->httpStatus() === $status, $message); return; }
    throw new T19CoreFailure($message);
}

$process = static function (string $name, $fallback) { $value = getenv($name); return $value !== false ? $value : $fallback; };
$host = (string) $process('GEO_DATABASE_HOSTNAME', Env::get('database.hostname', ''));
$port = (int) $process('GEO_DATABASE_HOSTPORT', Env::get('database.hostport', 0));
$database = (string) $process('GEO_DATABASE_DATABASE', Env::get('database.database', ''));
$user = (string) $process('GEO_DATABASE_USERNAME', Env::get('database.username', ''));
$password = (string) $process('GEO_DATABASE_PASSWORD', Env::get('database.password', ''));
if ($host !== '127.0.0.1' || $port === 3306 || preg_match('/_test$/D', $database) !== 1) { fwrite(STDERR, "FAIL: isolated T19 test database required\n"); exit(1); }

$prefix = 't19_core_';
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
$failure = null;

try {
    try { $pdo->exec('DELETE FROM `' . $prefix . 'audit_logs`'); } catch (Throwable $ignored) {}
    $manager->refresh();
    $now = 1787328000;
    $company = $pdo->prepare('INSERT INTO `' . $prefix . 'companies` (`name`,`status`,`createtime`,`updatetime`) VALUES (?,?,?,?)');
    $company->execute(['Company A', 'normal', $now, $now]); $companyA = (int) $pdo->lastInsertId();
    $company->execute(['Company B', 'normal', $now, $now]); $companyB = (int) $pdo->lastInsertId();
    $profile = $pdo->prepare('INSERT INTO `' . $prefix . 'company_profiles` (`company_id`,`brand`,`industry`,`products_json`,`website`,`introduction`,`contacts_ciphertext`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?)');
    $profile->execute([$companyA, 'Acme GEO', 'Software', '["GEO service"]', 'https://example.invalid', 'Acme GEO serves small businesses.', 'encrypted', $now, $now]);
    $profile->execute([$companyB, 'Other Brand', 'Retail', '["Other"]', null, 'Other tenant.', 'encrypted', $now, $now]);
    $keyword = $pdo->prepare('INSERT INTO `' . $prefix . 'keywords` (`company_id`,`keyword`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?)');
    $keyword->execute([$companyA, 'GEO optimization', 'normal', 101, 101, $now, $now]); $keywordA = (int) $pdo->lastInsertId();
    $keyword->execute([$companyB, 'Other keyword', 'normal', 202, 202, $now, $now]); $keywordB = (int) $pdo->lastInsertId();
    $question = $pdo->prepare('INSERT INTO `' . $prefix . 'questions` (`company_id`,`keyword_id`,`text`,`text_hash`,`source`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $question->execute([$companyA, $keywordA, 'Existing question?', hash('sha256', 'existing question?', true), 'manual', 'normal', 101, 101, $now, $now]); $existingQuestionA = (int) $pdo->lastInsertId();
    $question->execute([$companyB, $keywordB, 'Other question?', hash('sha256', 'other question?', true), 'manual', 'normal', 202, 202, $now, $now]);
    $knowledge = $pdo->prepare('INSERT INTO `' . $prefix . 'knowledge_items` (`company_id`,`type`,`title`,`content`,`oss_file_index_id`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $knowledge->execute([$companyA, 'text', 'Verified fact', 'Acme GEO supports auditable answer evidence.', null, 'normal', 101, 101, $now, $now]); $knowledgeA = (int) $pdo->lastInsertId();

    $contextA = TenantContext::enterprise(101, $companyA, 't19_test');
    $contextB = TenantContext::enterprise(202, $companyB, 't19_test');
    $audit = new PdoAuditLogger($pdo, $prefix);
    $gateway = new TenantDataGateway($pdo, TenantResourceCatalog::registry($prefix), $audit);
    $clock = static fn(): int => $now;

    $questionProvider = new T19SequenceAiProvider(['{"questions":["How does Acme GEO help small businesses?","Existing question?","How does Acme GEO help small businesses?"]}']);
    $questionService = new QuestionGenerationService($pdo, $gateway, $audit, $clock);
    $generated = $questionService->generate(['keyword_id' => $keywordA, 'knowledge_item_ids' => [$knowledgeA]], $questionProvider, $contextA);
    t19CoreAssert($generated['generated_count'] === 1 && $generated['duplicate_count'] === 2, 'AI question generation deduplicates existing and repeated output');
    $generatedQuestion = $generated['questions'][0];
    t19CoreAssert($generatedQuestion['source'] === 'ai' && $generatedQuestion['status'] === 'pending_review', 'AI question is saved with provenance and requires review');
    t19CoreAssert((int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}questions` WHERE `source`='manual'")->fetchColumn() === 2, 'AI generation does not overwrite manual questions');
    t19CoreReject(static fn() => $questionService->review($generatedQuestion['id'], true, $contextB), 404, 'another tenant cannot review generated questions');
    $enabledQuestion = $questionService->review($generatedQuestion['id'], true, $contextA);
    t19CoreAssert($enabledQuestion['status'] === 'normal' && $enabledQuestion['reviewed_by'] === 101, 'human review enables an AI question');

    $validAnswer = json_encode([
        'answer' => 'Acme GEO serves small businesses and supports auditable answer evidence.',
        'recommendation_reason' => 'The verified service matches the question.',
        'facts' => [
            ['claim' => 'Acme GEO serves small businesses.', 'source_type' => 'profile', 'source_id' => 'introduction', 'evidence_quote' => 'Acme GEO serves small businesses.'],
            ['claim' => 'It supports auditable answer evidence.', 'source_type' => 'knowledge', 'source_id' => $knowledgeA, 'evidence_quote' => 'supports auditable answer evidence'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $invalidAnswer = json_encode([
        'answer' => 'Unsupported claim.', 'recommendation_reason' => '',
        'facts' => [['claim' => 'Unsupported claim.', 'source_type' => 'knowledge', 'source_id' => 999999, 'evidence_quote' => 'Unsupported claim']],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fabricatedAnswer = json_encode([
        'answer' => 'Acme GEO has one million customers.', 'recommendation_reason' => '',
        'facts' => [['claim' => 'Acme GEO has one million customers.', 'source_type' => 'knowledge', 'source_id' => $knowledgeA, 'evidence_quote' => 'one million customers']],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $answerService = new TargetAnswerService($pdo, $gateway, $audit, $clock);
    $answer = $answerService->generate($enabledQuestion['id'], [$knowledgeA], new T19SourceContractAiProvider(), $contextA);
    t19CoreAssert(
        $answer['version_no'] === 1
        && $answer['status'] === 'pending_review'
        && count($answer['facts']) === 3
        && strpos($answer['answer'], 'pricing or case-study details') !== false,
        'GEO target answer follows the evidence-backed recommendation quality contract'
    );
    t19CoreReject(static fn() => $answerService->generate($enabledQuestion['id'], [$knowledgeA], new T19SequenceAiProvider([$invalidAnswer]), $contextA), 422, 'unknown fact references reject the complete AI answer');
    t19CoreReject(static fn() => $answerService->generate($enabledQuestion['id'], [$knowledgeA], new T19SequenceAiProvider([$fabricatedAnswer]), $contextA), 422, 'a valid source ID cannot support a fabricated quote');
    t19CoreAssert((int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}geo_answer_versions` WHERE `question_id`=" . (int) $enabledQuestion['id'])->fetchColumn() === 1, 'invalid answer does not create a partial version');

    $revised = $answerService->revise($answer['id'], [
        'answer' => 'Acme GEO supports auditable answer evidence for small businesses.',
        'recommendation_reason' => 'Human-confirmed wording.',
        'facts' => $answer['facts'],
    ], $contextA);
    t19CoreAssert($revised['version_no'] === 2 && $revised['source'] === 'manual', 'manual edit appends a new answer version');
    t19CoreAssert($answerService->detail($answer['id'], $contextA)['answer'] !== $revised['answer'], 'earlier answer version is not overwritten');
    t19CoreReject(static fn() => $answerService->detail($revised['id'], $contextB), 404, 'another tenant cannot read GEO answers');

    $articleContext = new GeoArticleContextService($pdo, $gateway);
    t19CoreReject(static fn() => $articleContext->context($revised['id'], $contextA), 409, 'unconfirmed answer cannot enter article generation');
    $confirmed = $answerService->confirm($revised['id'], $contextA);
    $contextPayload = $articleContext->context($confirmed['id'], $contextA);
    t19CoreAssert($contextPayload['answer'] === $confirmed['answer'] && count($contextPayload['facts']) === 3, 'confirmed GEO answer enters existing article context with evidence');
    $assembled = (new AiPromptAssembler())->assemble([
        'prompt' => ['content' => 'Write an evidence-led article.'], 'company' => [], 'keyword' => [],
        'question' => [], 'knowledge' => [], 'geo_target_answer' => $contextPayload,
    ], 256);
    $assembledContext = json_decode($assembled->userPrompt(), true, 32, JSON_THROW_ON_ERROR);
    t19CoreAssert(($assembledContext['geo_target_answer']['answer'] ?? null) === $confirmed['answer'], 'existing AI article prompt receives the confirmed GEO target answer');

    $prompt = $pdo->prepare('INSERT INTO `' . $prefix . 'prompt_templates` (`company_id`,`logical_key`,`name`,`scene`,`version_no`,`content`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $prompt->execute([$companyA, str_repeat('f', 32), 'T19 prompt', 'article', 1, 'Use confirmed GEO facts.', 'normal', 101, 101, $now, $now]); $promptId = (int) $pdo->lastInsertId();
    $dispatcher = new T19CoreDispatcher();
    $generation = new AiGenerationService(
        $pdo, $gateway, $audit, $dispatcher, new TenantTaskEnvelope(str_repeat('t', 64), $clock), $prefix,
        ['provider' => 'mock', 'queue' => 'geo_ai_generation', 'max_attempts' => 3], $clock
    );
    $articleTask = $generation->create([
        'keyword_id' => $keywordA, 'question_id' => $enabledQuestion['id'], 'prompt_template_id' => $promptId,
        'knowledge_item_ids' => [$knowledgeA], 'geo_answer_version_id' => $confirmed['id'],
    ], 't19-confirmed-answer', $contextA);
    $taskSnapshot = json_decode((string) $pdo->query("SELECT `input_snapshot_json` FROM `{$prefix}geo_async_jobs` WHERE `id`=" . (int) $articleTask['id'])->fetchColumn(), true, 64, JSON_THROW_ON_ERROR);
    t19CoreAssert(
        ($taskSnapshot['geo_target_answer']['id'] ?? null) === $confirmed['id']
        && ($taskSnapshot['geo_target_answer']['answer'] ?? null) === $confirmed['answer']
        && ($taskSnapshot['geo_target_answer']['profile_snapshot']['brand'] ?? null) === 'Acme GEO'
        && ($taskSnapshot['geo_target_answer']['knowledge_snapshot'][0]['id'] ?? null) === $knowledgeA,
        'existing asynchronous article task snapshots the confirmed GEO answer and frozen fact sources'
    );
    $articleProvider = new T19SequenceAiProvider(['{"title":"GEO article","content":"Evidence-led GEO content."}']);
    $runner = new AiGenerationRunner(
        $pdo, $gateway, $audit, new AiArticleWriter($pdo, $gateway, $audit, $clock), new AiPromptAssembler(),
        new ArticleOutputParser(191, 100000), new AiCostCalculator(null, null, 'CNY'), static fn() => $articleProvider,
        $prefix, ['provider' => 'mock', 'ark' => [], 'pricing' => [], 'max_output_tokens' => 256], $clock
    );
    $runner->run($articleTask['id'], $contextA);
    $generatedArticle = $generation->result($articleTask['id'], $contextA);
    $generatedAnswerLink = $pdo->query("SELECT `geo_answer_version_id` FROM `{$prefix}article_versions` WHERE `id`=" . (int) $generatedArticle['version']['id'])->fetchColumn();
    t19CoreAssert((int) $generatedAnswerLink === $confirmed['id'], 'AI article writer persists the confirmed GEO answer provenance');
    $article = $pdo->prepare('INSERT INTO `' . $prefix . 'articles` (`company_id`,`keyword_id`,`question_id`,`title`,`current_version_id`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,NULL,?,?,?,?,?)');
    $article->execute([$companyA, $keywordA, $enabledQuestion['id'], 'Draft', 'draft', 101, 101, $now, $now]); $articleId = (int) $pdo->lastInsertId();
    $version = $pdo->prepare('INSERT INTO `' . $prefix . 'article_versions` (`company_id`,`article_id`,`version_no`,`title_snapshot`,`content`,`source`,`prompt_template_id`,`created_by`,`createtime`) VALUES (?,?,?,?,?,?,?,?,?)');
    $version->execute([$companyA, $articleId, 1, 'Draft', 'Draft content', 'manual', $promptId, 101, $now]); $articleVersionId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE `' . $prefix . 'articles` SET `current_version_id`=? WHERE `id`=?')->execute([$articleVersionId, $articleId]);
    $articleContext->attach($confirmed['id'], $articleVersionId, $contextA);
    t19CoreAssert((int) $pdo->query("SELECT `geo_answer_version_id` FROM `{$prefix}article_versions` WHERE `id`={$articleVersionId}")->fetchColumn() === $confirmed['id'], 'existing article version records its confirmed GEO answer source');
    $article->execute([$companyA, $keywordA, $existingQuestionA, 'Wrong question', 'draft', 101, 101, $now, $now]); $wrongArticleId = (int) $pdo->lastInsertId();
    $version->execute([$companyA, $wrongArticleId, 1, 'Wrong question', 'Draft', 'manual', $promptId, 101, $now]); $wrongVersionId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE `' . $prefix . 'articles` SET `current_version_id`=? WHERE `id`=?')->execute([$wrongVersionId, $wrongArticleId]);
    t19CoreReject(static fn() => $articleContext->attach($confirmed['id'], $wrongVersionId, $contextA), 409, 'GEO answer cannot attach to an article for another question');

    $versionTemplate = $pdo->query("SELECT * FROM `{$prefix}geo_answer_versions` WHERE `id`=" . (int) $revised['id'])->fetch(PDO::FETCH_ASSOC);
    $bulkVersion = $pdo->prepare(
        "INSERT INTO `{$prefix}geo_answer_versions`"
        . ' (`company_id`,`question_id`,`keyword_id`,`version_no`,`answer_text`,`recommendation_reason`,`facts_json`,`evidence_json`,`profile_snapshot_json`,`knowledge_snapshot_json`,`source`,`status`,`created_by`,`created_at`)'
        . ' VALUES (?,?,?,?,?,?,?,?,?,?,\'manual\',\'pending_review\',101,?)'
    );
    for ($versionNo = 3; $versionNo <= 1001; $versionNo++) {
        $bulkVersion->execute([
            $companyA, $enabledQuestion['id'], $keywordA, $versionNo,
            $versionTemplate['answer_text'], $versionTemplate['recommendation_reason'],
            $versionTemplate['facts_json'], $versionTemplate['evidence_json'],
            $versionTemplate['profile_snapshot_json'], $versionTemplate['knowledge_snapshot_json'],
            date('Y-m-d H:i:s', $now),
        ]);
    }
    $versionAfterLargeHistory = $answerService->revise($revised['id'], [
        'answer' => 'Acme GEO supports auditable answer evidence for small businesses.',
        'recommendation_reason' => 'Version sequence remains writable.',
        'facts' => $answer['facts'],
    ], $contextA);
    t19CoreAssert($versionAfterLargeHistory['version_no'] === 1002, 'GEO answer versioning remains writable after more than 1000 versions');

    fwrite(STDOUT, "PASS: T19 GEO question answer and content services\n");
} catch (Throwable $exception) {
    $failure = $exception;
}
if ($failure !== null) { fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . PHP_EOL); exit(1); }

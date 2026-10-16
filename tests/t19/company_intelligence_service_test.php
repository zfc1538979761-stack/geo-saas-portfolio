<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);

require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\ai\AiGenerationRequest;
use app\common\ai\AiGenerationResult;
use app\common\ai\AiProvider;
use app\common\company\CompanyProfileService;
use app\common\company\ContactCipher;
use app\common\company\intelligence\CompanyDiscoveryException;
use app\common\company\intelligence\CompanyDiscoveryProvider;
use app\common\company\intelligence\CompanyDiscoveryRequest;
use app\common\company\intelligence\CompanyDiscoveryResult;
use app\common\company\intelligence\CompanyIntelligenceService;
use app\common\company\intelligence\CompanyProfileDraftOrganizer;
use app\common\company\intelligence\UnavailableCompanyDiscoveryProvider;
use app\common\geo\QuestionGenerationService;
use app\common\migration\MigrationManager;
use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantCompanyGateway;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantContextResolver;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantResourceCatalog;
use think\Env;

final class T19CompanyIntelligenceFailure extends RuntimeException
{
}

final class T19CompanyTestCipher implements ContactCipher
{
    public function encrypt(array $contacts, int $companyId): string
    {
        return json_encode(['company_id' => $companyId, 'contacts' => $contacts], JSON_THROW_ON_ERROR);
    }

    public function decrypt(string $ciphertext, int $companyId): array
    {
        $decoded = json_decode($ciphertext, true, 16, JSON_THROW_ON_ERROR);
        if ((int) ($decoded['company_id'] ?? 0) !== $companyId) {
            throw new RuntimeException('cipher tenant mismatch');
        }
        return (array) ($decoded['contacts'] ?? []);
    }
}

final class T19SameNameDiscoveryProvider implements CompanyDiscoveryProvider
{
    public function providerCode(): string { return 'fake_company_discovery'; }

    public function discover(CompanyDiscoveryRequest $request): CompanyDiscoveryResult
    {
        $candidate = static function (string $key, string $region, string $website): array {
            return [
                'external_key' => $key,
                'name' => '星河科技有限公司',
                'region' => $region,
                'website' => $website,
                'unified_social_credit_code' => null,
                'public_summary' => $region . '企业软件服务商',
                'sources' => [[
                    'source_key' => $key . '-official',
                    'source_url' => $website . '/about',
                    'source_title' => $region . '星河科技官网',
                    'public_excerpt' => '提供企业软件与数据服务。',
                    'published_at' => null,
                ]],
            ];
        };
        return new CompanyDiscoveryResult([
            $candidate('shanghai', '上海市浦东新区', 'https://shanghai-xinghe.example'),
            $candidate('shenzhen', '广东省深圳市', 'https://shenzhen-xinghe.example'),
        ], $this->providerCode(), 'fake-discovery-1', false);
    }
}

final class T19CompanyDraftAiProvider implements AiProvider
{
    public function providerCode(): string { return 'fake_ai'; }

    public function generate(AiGenerationRequest $request): AiGenerationResult
    {
        $evidence = json_decode($request->userPrompt(), true, 64, JSON_THROW_ON_ERROR);
        $sourceId = (int) ($evidence['sources'][0]['id'] ?? 0);
        $raw = json_encode([
            'facts' => [
                ['field_key' => 'company_name', 'value' => '星河科技有限公司', 'source_ids' => [$sourceId]],
                ['field_key' => 'region', 'value' => '上海市浦东新区', 'source_ids' => [$sourceId]],
                ['field_key' => 'brand', 'value' => '星河新品牌', 'source_ids' => [$sourceId]],
                ['field_key' => 'website', 'value' => 'https://shanghai-xinghe.example', 'source_ids' => [$sourceId]],
                ['field_key' => 'industry', 'value' => '企业软件', 'source_ids' => [$sourceId]],
                ['field_key' => 'main_business', 'value' => '企业软件与数据服务', 'source_ids' => [$sourceId]],
                ['field_key' => 'products_services', 'value' => ['企业软件', '数据服务'], 'source_ids' => [$sourceId]],
                ['field_key' => 'introduction', 'value' => '面向企业提供软件与数据服务。', 'source_ids' => [$sourceId]],
                ['field_key' => 'advantages', 'value' => ['公开案例可追溯'], 'source_ids' => [$sourceId]],
            ],
            'inferences' => [[
                'field_key' => 'qualifications',
                'value' => ['可能具备软件服务经验'],
                'reason' => '根据公开简介概括',
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new AiGenerationResult($raw, 'fake_ai', 'fake-profile-model', 'fake-ai-request-1', null, null, 0);
    }
}

final class T19QuestionAiProvider implements AiProvider
{
    public function providerCode(): string { return 'fake_ai'; }
    public function generate(AiGenerationRequest $request): AiGenerationResult
    {
        return new AiGenerationResult(
            '{"questions":["上海企业如何选择可追溯的数据服务？"]}',
            'fake_ai',
            'fake-question-model',
            'fake-question-request-1',
            null,
            null,
            0
        );
    }
}

function t19CompanyIntelligenceAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new T19CompanyIntelligenceFailure($message); }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}

function t19CompanyIntelligenceReject(callable $operation, string $category, int $status, string $message): CompanyDiscoveryException
{
    try {
        $operation();
    } catch (CompanyDiscoveryException $exception) {
        t19CompanyIntelligenceAssert(
            $exception->category() === $category && $exception->httpStatus() === $status,
            $message
        );
        return $exception;
    }
    throw new T19CompanyIntelligenceFailure($message . ' did not reject');
}

function t19CompanyIntelligenceCleanup(PDO $pdo, string $prefix): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $statement = $pdo->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE ?'
    );
    $statement->execute([str_replace(['_', '%'], ['\\_', '\\%'], $prefix) . '%']);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string) $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

try {
    t19CompanyIntelligenceAssert(class_exists(CompanyIntelligenceService::class), 'company intelligence service exists');
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$process = static function (string $name, $fallback) {
    $value = getenv($name);
    return $value !== false ? $value : $fallback;
};
$host = (string) $process('GEO_DATABASE_HOSTNAME', Env::get('database.hostname', ''));
$port = (int) $process('GEO_DATABASE_HOSTPORT', Env::get('database.hostport', 0));
$database = (string) $process('GEO_DATABASE_DATABASE', Env::get('database.database', ''));
$user = (string) $process('GEO_DATABASE_USERNAME', Env::get('database.username', ''));
$password = (string) $process('GEO_DATABASE_PASSWORD', Env::get('database.password', ''));
if ($host !== '127.0.0.1' || $port < 1 || $port === 3306 || preg_match('/_test$/D', $database) !== 1) {
    fwrite(STDERR, "FAIL: T19 company intelligence test requires an isolated non-3306 _test database\n");
    exit(1);
}

$prefix = 't19_company_intel_';
$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);
$manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
$failure = null;

try {
    t19CompanyIntelligenceCleanup($pdo, $prefix);
    $manager->refresh();
    $companyInsert = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'companies` (`name`,`status`,`createtime`,`updatetime`) VALUES (?,\'normal\',?,?)'
    );
    $userInsert = $pdo->prepare(
        'INSERT INTO `' . $prefix . 'user` (`company_id`,`username`,`password_hash`,`role`,`status`,`createtime`,`updatetime`)'
        . ' VALUES (?,?,?,\'company_owner\',\'normal\',?,?)'
    );
    $now = 1787371200;
    $companyInsert->execute(['星河科技有限公司', $now, $now]);
    $companyA = (int) $pdo->lastInsertId();
    $userInsert->execute([$companyA, 't19-intel-a', password_hash('test-only-a', PASSWORD_DEFAULT), $now, $now]);
    $userA = (int) $pdo->lastInsertId();
    $companyInsert->execute(['隔离企业有限公司', $now, $now]);
    $companyB = (int) $pdo->lastInsertId();
    $userInsert->execute([$companyB, 't19-intel-b', password_hash('test-only-b', PASSWORD_DEFAULT), $now, $now]);
    $userB = (int) $pdo->lastInsertId();
    $contextA = TenantContextResolver::fromTrustedIdentity($userA, $companyA, 't19_company_test');
    $contextB = TenantContextResolver::fromTrustedIdentity($userB, $companyB, 't19_company_test');

    $audit = new PdoAuditLogger($pdo, $prefix);
    $gateway = new TenantDataGateway($pdo, TenantResourceCatalog::registry($prefix), $audit);
    $profile = new CompanyProfileService(
        $pdo,
        $gateway,
        new TenantCompanyGateway($pdo, $prefix),
        new T19CompanyTestCipher(),
        $audit,
        null,
        static fn(): int => $now
    );
    $profile->save([
        'company_name' => '星河科技有限公司',
        'brand' => '既有已确认品牌',
        'industry' => null,
        'products_services' => [],
        'website' => null,
        'introduction' => null,
        'mobile' => '13800138000',
        'email' => 'owner@example.test',
    ], $contextA);
    $service = new CompanyIntelligenceService(
        $pdo,
        $gateway,
        $profile,
        $audit,
        $prefix,
        new CompanyProfileDraftOrganizer(),
        static fn(): string => '2026-08-22 12:00:00.000000'
    );

    t19CompanyIntelligenceReject(
        static fn() => $service->discover(
            ['company_name' => '星河科技有限公司', 'region' => '上海市'],
            new UnavailableCompanyDiscoveryProvider('responses_web_search', 'ToolNotOpen'),
            $contextA
        ),
        'external_capability_unavailable',
        503,
        'ToolNotOpen discovery fails safely'
    );
    t19CompanyIntelligenceAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}company_discovery_candidates`")->fetchColumn() === 0,
        'ToolNotOpen does not persist fake candidates'
    );

    $unexpectedProvider = new class implements CompanyDiscoveryProvider {
        public function providerCode(): string { return 'broken_company_discovery'; }
        public function discover(CompanyDiscoveryRequest $request): CompanyDiscoveryResult
        {
            throw new RuntimeException('unexpected provider client failure');
        }
    };
    t19CompanyIntelligenceReject(
        static fn() => $service->discover(
            ['company_name' => '星河科技有限公司', 'region' => '上海市'],
            $unexpectedProvider,
            $contextA
        ),
        'provider_error',
        502,
        'unexpected Provider failures are normalized safely'
    );
    t19CompanyIntelligenceAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}company_discovery_runs` WHERE `status`='running'")->fetchColumn() === 0,
        'unexpected Provider failures never leave a discovery run running'
    );

    $discovery = $service->discover(
        ['company_name' => '星河科技有限公司', 'region' => '上海市'],
        new T19SameNameDiscoveryProvider(),
        $contextA
    );
    t19CompanyIntelligenceAssert(
        count($discovery['candidates']) === 2
        && $discovery['candidates'][0]['status'] === 'discovered'
        && $discovery['candidates'][1]['status'] === 'discovered'
        && $discovery['candidates'][0]['region'] !== $discovery['candidates'][1]['region'],
        'same-name enterprises remain separate discovered candidates'
    );
    t19CompanyIntelligenceAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}company_profile_drafts` WHERE `status`='verified'")->fetchColumn() === 0,
        'candidate discovery does not create verified profile data'
    );
    t19CompanyIntelligenceReject(
        static fn() => $service->candidates((int) $discovery['run']['id'], $contextB),
        'not_found',
        404,
        'another tenant cannot view discovery candidates'
    );

    try {
        $service->confirmCandidate(
            (int) $discovery['candidates'][0]['id'],
            new class implements AiProvider {
                public function providerCode(): string { return 'broken_ai'; }
                public function generate(AiGenerationRequest $request): AiGenerationResult
                {
                    throw new RuntimeException('profile organizer unavailable');
                }
            },
            $contextA
        );
        throw new T19CompanyIntelligenceFailure('failed profile organization must reject');
    } catch (RuntimeException $exception) {
        t19CompanyIntelligenceAssert(
            $exception->getMessage() === 'profile organizer unavailable',
            'profile organization failure is surfaced without a partial draft'
        );
    }
    $failedCandidate = $gateway->find(
        'company_discovery_candidates',
        (int) $discovery['candidates'][0]['id'],
        $contextA
    );
    t19CompanyIntelligenceAssert(
        is_array($failedCandidate)
        && $failedCandidate['status'] === 'discovered'
        && (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}company_profile_drafts`")->fetchColumn() === 0,
        'failed profile organization releases its short-lived claim for a safe retry'
    );

    $draft = $service->confirmCandidate(
        (int) $discovery['candidates'][0]['id'],
        new T19CompanyDraftAiProvider(),
        $contextA
    );
    t19CompanyIntelligenceAssert(
        $draft['status'] === 'discovered'
        && $draft['draft']['region'] === '上海市浦东新区'
        && $draft['facts'][0]['source_ids'] !== []
        && $draft['inferences'][0]['fact_type'] === 'ai_inference',
        'confirmed candidate creates an evidence-backed discovered draft with separate inference'
    );
    $storedDraft = $gateway->find('company_profile_drafts', (int) $draft['id'], $contextA);
    t19CompanyIntelligenceAssert(
        is_array($storedDraft)
        && strpos((string) $storedDraft['profile_baseline_json'], '13800138000') === false
        && strpos((string) $storedDraft['profile_baseline_json'], 'owner@example.test') === false
        && strpos((string) $storedDraft['profile_baseline_json'], 'contacts') === false,
        'draft baseline snapshot never duplicates decrypted contact details'
    );
    t19CompanyIntelligenceAssert(
        $profile->get($contextA)['brand'] === '既有已确认品牌',
        'candidate confirmation does not write the formal company profile'
    );
    t19CompanyIntelligenceReject(
        static fn() => $service->draft((int) $draft['id'], $contextB),
        'not_found',
        404,
        'another tenant cannot view a profile draft'
    );

    $reviewed = $draft['draft'];
    $reviewed['introduction'] = '用户确认修改后的企业简介。';
    $conflict = t19CompanyIntelligenceReject(
        static fn() => $service->confirmDraft((int) $draft['id'], $reviewed, [], $contextA),
        'profile_conflict_requires_resolution',
        409,
        'existing verified profile data requires explicit conflict resolution'
    );
    t19CompanyIntelligenceAssert(
        ($conflict->details()['conflicts']['brand']['existing'] ?? null) === '既有已确认品牌'
        && $profile->get($contextA)['brand'] === '既有已确认品牌',
        'profile conflict reports both values and does not write'
    );

    $invalidReviewed = $reviewed;
    $invalidReviewed['brand'] = str_repeat('超', 192);
    t19CompanyIntelligenceReject(
        static fn() => $service->confirmDraft(
            (int) $draft['id'],
            $invalidReviewed,
            ['brand' => 'use_draft'],
            $contextA
        ),
        'invalid_request',
        422,
        'profile validator errors remain safe company intelligence API errors'
    );

    $confirmed = $service->confirmDraft(
        (int) $draft['id'],
        $reviewed,
        ['brand' => 'keep_existing'],
        $contextA
    );
    $savedProfile = $profile->get($contextA);
    t19CompanyIntelligenceAssert(
        $confirmed['status'] === 'verified'
        && $confirmed['knowledge_item_id'] > 0
        && $confirmed['draft']['introduction'] === '用户确认修改后的企业简介。'
        && $confirmed['inferences'] === []
        && count(array_filter($confirmed['facts'], static fn(array $fact): bool => $fact['status'] !== 'verified')) === 0
        && $savedProfile['brand'] === '既有已确认品牌'
        && $savedProfile['website'] === 'https://shanghai-xinghe.example'
        && $savedProfile['contacts']['mobile'] === '13800138000',
        'verified confirmation returns the confirmed read model and preserves resolved profile contacts'
    );
    $knowledgeId = (int) $confirmed['knowledge_item_id'];
    $knowledge = $gateway->find('knowledge_items', $knowledgeId, $contextA);
    t19CompanyIntelligenceAssert(
        is_array($knowledge)
        && strpos((string) $knowledge['content'], 'https://shanghai-xinghe.example/about') !== false
        && strpos((string) $knowledge['content'], '上海市浦东新区星河科技官网') !== false,
        'verified knowledge preserves source URL and title evidence'
    );
    $introductionFact = $pdo->query(
        "SELECT `fact_type`,`source_ids_json` FROM `{$prefix}company_profile_draft_facts`"
        . " WHERE `company_id`={$companyA} AND `draft_id`=" . (int) $draft['id']
        . " AND `field_key`='introduction'"
    )->fetch(PDO::FETCH_ASSOC);
    t19CompanyIntelligenceAssert(
        is_array($introductionFact)
        && $introductionFact['fact_type'] === 'user_input'
        && json_decode((string) $introductionFact['source_ids_json'], true) === []
        && strpos((string) $knowledge['content'], '用户确认补充，无公开来源') !== false,
        'user-edited sourced facts are reclassified without stale source attribution'
    );
    $verifiedDraftRow = $gateway->find('company_profile_drafts', (int) $draft['id'], $contextA);
    t19CompanyIntelligenceAssert(
        is_array($verifiedDraftRow)
        && strpos((string) $verifiedDraftRow['confirmed_snapshot_json'], '13800138000') === false
        && strpos((string) $verifiedDraftRow['confirmed_snapshot_json'], 'owner@example.test') === false
        && strpos((string) $verifiedDraftRow['confirmed_snapshot_json'], 'contacts') === false,
        'confirmed snapshot never duplicates decrypted contact details'
    );
    $confirmedAgain = $service->confirmDraft((int) $draft['id'], $reviewed, ['brand' => 'keep_existing'], $contextA);
    t19CompanyIntelligenceAssert(
        (int) $confirmedAgain['knowledge_item_id'] === $knowledgeId
        && (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}knowledge_items` WHERE `company_id`={$companyA}")->fetchColumn() === 1,
        'repeated draft confirmation is idempotent'
    );

    $laterDiscovery = $service->discover(
        ['company_name' => '星河科技有限公司', 'region' => '上海市'],
        new T19SameNameDiscoveryProvider(),
        $contextA
    );
    $laterDraft = $service->confirmCandidate(
        (int) $laterDiscovery['candidates'][0]['id'],
        new T19CompanyDraftAiProvider(),
        $contextA
    );
    t19CompanyIntelligenceAssert(
        $laterDraft['status'] === 'discovered'
        && $service->latestVerifiedKnowledgeItemId($contextA) === $knowledgeId
        && (int) ($service->status($contextA)['draft']['id'] ?? 0) === (int) $laterDraft['id'],
        'a newer discovered draft does not hide the latest verified knowledge handoff'
    );

    $keywordId = $gateway->insert('keywords', [
        'keyword' => '企业数据服务', 'status' => 'normal', 'created_by' => $userA,
        'updated_by' => $userA, 'createtime' => $now, 'updatetime' => $now, 'deletetime' => null,
    ], $contextA);
    $questions = (new QuestionGenerationService($pdo, $gateway, $audit, static fn(): int => $now))->generate(
        ['keyword_id' => $keywordId, 'knowledge_item_ids' => [$knowledgeId]],
        new T19QuestionAiProvider(),
        $contextA
    );
    t19CompanyIntelligenceAssert(
        count($questions['questions']) === 1
        && $questions['questions'][0]['status'] === 'pending_review'
        && $questions['questions'][0]['source'] === 'ai',
        'verified company profile enters the existing T19 AI question generation chain'
    );

    fwrite(STDOUT, "PASS: T19 company intelligence service integration\n");
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    try { t19CompanyIntelligenceCleanup($pdo, $prefix); } catch (Throwable $ignored) {}
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . get_class($failure) . ' ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}

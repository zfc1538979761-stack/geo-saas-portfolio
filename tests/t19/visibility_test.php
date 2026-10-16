<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\geo\GeoException;
use app\common\geo\VisibilityClassifier;
use app\common\geo\VisibilityPublicationTimeline;
use app\common\geo\VisibilityService;
use app\common\geo\visibility\UnavailableVisibilityProvider;
use app\common\geo\visibility\VisibilityProvider;
use app\common\geo\visibility\VisibilityRequest;
use app\common\geo\visibility\VisibilityResponse;
use app\common\migration\MigrationManager;
use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantResourceCatalog;
use think\Env;

final class T19VisibilityFailure extends RuntimeException {}
final class T19FakeVisibilityProvider implements VisibilityProvider
{
    private array $responses;
    private int $index = 0;
    public function __construct(array $responses) { $this->responses = $responses; }
    public function providerCode(): string { return 'fake_visibility'; }
    public function query(VisibilityRequest $request): VisibilityResponse
    {
        return $this->responses[min($this->index++, count($this->responses) - 1)];
    }
}
final class T19UnexpectedVisibilityProvider implements VisibilityProvider
{
    public function providerCode(): string { return 'fake_visibility'; }
    public function query(VisibilityRequest $request): VisibilityResponse { throw new RuntimeException('fixture failure'); }
}
final class T19CallbackVisibilityProvider implements VisibilityProvider
{
    private $callback;
    public function __construct(callable $callback) { $this->callback = $callback; }
    public function providerCode(): string { return 'fake_visibility'; }
    public function query(VisibilityRequest $request): VisibilityResponse
    {
        call_user_func($this->callback);
        return new VisibilityResponse('No matching company was found.', [], ['query_mode' => 'fake'], 'fake_visibility', 'baseline-race');
    }
}
function t19VisibilityAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new T19VisibilityFailure($message); }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}
function t19VisibilityReject(callable $operation, int $status, string $message): void
{
    try { $operation(); } catch (GeoException $exception) { t19VisibilityAssert($exception->httpStatus() === $status, $message); return; }
    throw new T19VisibilityFailure($message);
}

$process = static function (string $name, $fallback) { $value = getenv($name); return $value !== false ? $value : $fallback; };
$host = (string) $process('GEO_DATABASE_HOSTNAME', Env::get('database.hostname', ''));
$port = (int) $process('GEO_DATABASE_HOSTPORT', Env::get('database.hostport', 0));
$database = (string) $process('GEO_DATABASE_DATABASE', Env::get('database.database', ''));
$user = (string) $process('GEO_DATABASE_USERNAME', Env::get('database.username', ''));
$password = (string) $process('GEO_DATABASE_PASSWORD', Env::get('database.password', ''));
if ($host !== '127.0.0.1' || $port === 3306 || preg_match('/_test$/D', $database) !== 1) { fwrite(STDERR, "FAIL: isolated T19 test database required\n"); exit(1); }

$prefix = 't19_visibility_';
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
$failure = null;
try {
    try { $pdo->exec('DELETE FROM `' . $prefix . 'audit_logs`'); } catch (Throwable $ignored) {}
    $manager->refresh();
    $now = 1787328000;
    $company = $pdo->prepare("INSERT INTO `{$prefix}companies` (`name`,`status`,`createtime`,`updatetime`) VALUES (?,?,?,?)");
    $company->execute(['A', 'normal', $now, $now]); $companyA = (int) $pdo->lastInsertId();
    $company->execute(['B', 'normal', $now, $now]); $companyB = (int) $pdo->lastInsertId();
    $company->execute(['C', 'normal', $now, $now]); $companyC = (int) $pdo->lastInsertId();
    $profile = $pdo->prepare("INSERT INTO `{$prefix}company_profiles` (`company_id`,`brand`,`industry`,`products_json`,`website`,`introduction`,`contacts_ciphertext`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?)");
    $profile->execute([$companyA, 'Acme GEO', 'Software', '[]', null, 'Verified profile.', 'x', $now, $now]);
    $profile->execute([$companyB, 'Other', 'Retail', '[]', null, 'Other profile.', 'x', $now, $now]);
    $profile->execute([$companyC, '', 'Software', '[]', null, 'Profile without a brand.', 'x', $now, $now]);
    $keyword = $pdo->prepare("INSERT INTO `{$prefix}keywords` (`company_id`,`keyword`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?)");
    $keyword->execute([$companyA, 'geo', 'normal', 101, 101, $now, $now]); $keywordA = (int) $pdo->lastInsertId();
    $keyword->execute([$companyB, 'other', 'normal', 202, 202, $now, $now]); $keywordB = (int) $pdo->lastInsertId();
    $keyword->execute([$companyC, 'empty-brand', 'normal', 303, 303, $now, $now]); $keywordC = (int) $pdo->lastInsertId();
    $question = $pdo->prepare("INSERT INTO `{$prefix}questions` (`company_id`,`keyword_id`,`text`,`text_hash`,`source`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $question->execute([$companyA, $keywordA, 'Which GEO service should I choose?', hash('sha256', 'a', true), 'manual', 'normal', 101, 101, $now, $now]); $questionA = (int) $pdo->lastInsertId();
    $question->execute([$companyA, $keywordA, 'Can Baseline finish after publication?', hash('sha256', 'race', true), 'manual', 'normal', 101, 101, $now, $now]); $questionRace = (int) $pdo->lastInsertId();
    $question->execute([$companyB, $keywordB, 'Other?', hash('sha256', 'b', true), 'manual', 'normal', 202, 202, $now, $now]);
    $question->execute([$companyC, $keywordC, 'Can an empty brand be detected?', hash('sha256', 'c', true), 'manual', 'normal', 303, 303, $now, $now]); $questionC = (int) $pdo->lastInsertId();
    $contextA = TenantContext::enterprise(101, $companyA, 't19_visibility');
    $contextB = TenantContext::enterprise(202, $companyB, 't19_visibility');
    $contextC = TenantContext::enterprise(303, $companyC, 't19_visibility');
    $audit = new PdoAuditLogger($pdo, $prefix);
    $gateway = new TenantDataGateway($pdo, TenantResourceCatalog::registry($prefix), $audit);
    $clockValue = $now; $clock = static function () use (&$clockValue): int { return $clockValue; };
    $timeline = new VisibilityPublicationTimeline($pdo, $prefix);
    $service = new VisibilityService($pdo, $gateway, $audit, new VisibilityClassifier(), $clock, $timeline);
    $emptyBrandCheckCount = (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}geo_visibility_checks`")->fetchColumn();
    t19VisibilityReject(static fn() => $service->check([
        'question_id' => $questionC, 'platform' => 'test-platform', 'model_name' => 'test-model',
        'check_type' => 'baseline', 'idempotency_key' => 'empty-brand-baseline',
    ], new T19UnexpectedVisibilityProvider(), $contextC), 409, 'an empty company brand is rejected as a clear precondition error');
    t19VisibilityAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}geo_visibility_checks`")->fetchColumn() === $emptyBrandCheckCount,
        'invalid Baseline input cannot leave a running visibility check behind'
    );
    $baselineInput = [
        'question_id' => $questionA, 'platform' => 'test-platform', 'model_name' => 'baseline-model',
        'check_type' => 'baseline', 'idempotency_key' => 'baseline-before-publish',
    ];
    $baseline = $service->check($baselineInput, new T19FakeVisibilityProvider([
        new VisibilityResponse('No matching company was found.', [], ['query_mode' => 'fake'], 'fake_visibility', 'baseline-1'),
    ]), $contextA);
    t19VisibilityAssert($baseline['status'] === 'succeeded' && $baseline['result']['status'] === 'not_visible', 'Baseline can be completed and stored before any publication record exists');
    $samePrePublicationBaseline = $service->check(array_merge($baselineInput, [
        'idempotency_key' => 'baseline-replacement-before-publish',
    ]), new T19UnexpectedVisibilityProvider(), $contextA);
    t19VisibilityAssert($samePrePublicationBaseline['check_id'] === $baseline['check_id'], 'Baseline dimension is idempotent and cannot be replaced under a different request key');
    $blockedBaseline = $service->check([
        'question_id' => $questionA, 'platform' => 'blocked-platform', 'model_name' => 'blocked-model',
        'check_type' => 'baseline', 'idempotency_key' => 'blocked-baseline-before-publish',
    ], new UnavailableVisibilityProvider('responses_web_search', 'ToolNotOpen'), $contextA);
    t19VisibilityAssert($blockedBaseline['status'] === 'failed', 'a blocked Baseline is persisted as failed');
    $baselineHistory = $service->history(['question_id' => $questionA], 1, 20, $contextA);
    t19VisibilityAssert(
        ($baselineHistory['latest_baseline_check']['check_id'] ?? null) === $blockedBaseline['check_id']
        && ($baselineHistory['latest_baseline_check']['status'] ?? null) === 'failed'
        && ($baselineHistory['latest_baseline_check']['external_error_code'] ?? null) === 'ToolNotOpen',
        'visibility history exposes the latest failed Baseline check for durable UI status'
    );

    t19VisibilityReject(static function () use ($service, $questionRace, $pdo, $prefix, $companyA, $keywordA, $now, &$clockValue, $contextA, $timeline): void {
        $result = $service->check([
            'question_id' => $questionRace, 'platform' => 'test-platform', 'model_name' => 'race-model',
            'check_type' => 'baseline', 'idempotency_key' => 'baseline-publication-race',
        ], new T19CallbackVisibilityProvider(static function () use ($pdo, $prefix, $companyA, $keywordA, $questionRace, $now, &$clockValue): void {
            $pdo->prepare("INSERT INTO `{$prefix}articles` (`company_id`,`keyword_id`,`question_id`,`title`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$companyA, $keywordA, $questionRace, 'Published during Baseline', 'published', 101, 101, $now, $now]);
            $raceArticleId = (int) $pdo->lastInsertId();
            $racePublishedAt = date('Y-m-d H:i:s', $now + 1);
            $pdo->prepare("INSERT INTO `{$prefix}publish_orders` (`order_no`,`company_id`,`article_id`,`article_version_id`,`status`,`total_amount`,`frozen_amount`,`idempotency_key`,`request_fingerprint`,`created_by`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute(['BASELINE-RACE-ORDER', $companyA, $raceArticleId, 1, 'published', '0.00', '0.00', 'baseline-race-order', str_repeat('r', 64), 101, $racePublishedAt, $racePublishedAt]);
            $raceOrderId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO `{$prefix}order_status_logs` (`company_id`,`order_id`,`order_item_id`,`from_status`,`to_status`,`source`,`event_key`,`reason`,`metadata_json`,`created_at`) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$companyA, $raceOrderId, null, 'processing', 'published', 'test', 'baseline-race-published', null, '{}', $racePublishedAt]);
            $clockValue = $now + 2;
        }), $contextA);
        throw new T19VisibilityFailure('race unexpectedly succeeded: detected=' . ($result['result']['detected_at'] ?? 'none') . ', published=' . ($timeline->firstPublishedAt($questionRace, $contextA) ?? 'none') . ', clock=' . $clockValue);
    }, 409, 'Baseline is rejected when publication occurs before its detection finishes');
    t19VisibilityAssert((int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}geo_visibility_results` WHERE `question_id`={$questionRace}")->fetchColumn() === 0, 'publication race cannot persist a post-publication Baseline result');
    $clockValue = $now;

    $pdo->prepare("INSERT INTO `{$prefix}articles` (`company_id`,`keyword_id`,`question_id`,`title`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$companyA, $keywordA, $questionA, 'Baseline article', 'published', 101, 101, $now, $now]);
    $articleId = (int) $pdo->lastInsertId();
    $publishedAt = date('Y-m-d H:i:s', $now + 60);
    $pdo->prepare("INSERT INTO `{$prefix}publish_orders` (`order_no`,`company_id`,`article_id`,`article_version_id`,`status`,`total_amount`,`frozen_amount`,`idempotency_key`,`request_fingerprint`,`created_by`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute(['BASELINE-ORDER-A', $companyA, $articleId, 1, 'published', '0.00', '0.00', 'baseline-order-a', str_repeat('a', 64), 101, $publishedAt, $publishedAt]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `{$prefix}order_status_logs` (`company_id`,`order_id`,`order_item_id`,`from_status`,`to_status`,`source`,`event_key`,`reason`,`metadata_json`,`created_at`) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$companyA, $orderId, null, 'processing', 'published', 'test', 'baseline-published-a', null, '{}', $publishedAt]);
    $sameBaseline = $service->check($baselineInput, new T19UnexpectedVisibilityProvider(), $contextA);
    t19VisibilityAssert($sameBaseline['check_id'] === $baseline['check_id'], 'an existing pre-publication Baseline remains idempotently readable after publication');
    t19VisibilityReject(static fn() => $service->check([
        'question_id' => $questionA, 'platform' => 'test-platform', 'model_name' => 'late-baseline-model',
        'check_type' => 'baseline', 'idempotency_key' => 'baseline-after-publish',
    ], new T19UnexpectedVisibilityProvider(), $contextA), 409, 'a new Baseline cannot be created after the corresponding content was published');
    $provider = new T19FakeVisibilityProvider([
        new VisibilityResponse('No matching company was found.', [], ['query_mode' => 'fake'], 'fake_visibility', 'v1'),
        new VisibilityResponse('Acme GEO offers this service.', [], [], 'fake_visibility', 'v2'),
        new VisibilityResponse('I recommend Acme GEO.', [['title' => 'Official', 'url' => 'https://example.invalid/about']], ['query_mode' => 'fake', 'explicit_recommendation' => true, 'recommendation_reason' => 'Matches the request.'], 'fake_visibility', 'v3'),
    ]);
    $base = ['question_id' => $questionA, 'platform' => 'test-platform', 'model_name' => 'test-model', 'check_type' => 'initial'];
    $notVisible = $service->check($base + ['idempotency_key' => 'initial-1'], $provider, $contextA);
    $mentioned = $service->check($base + ['idempotency_key' => 'initial-2'], $provider, $contextA);
    $recommended = $service->check($base + ['idempotency_key' => 'initial-3'], $provider, $contextA);
    t19VisibilityAssert([$notVisible['result']['status'], $mentioned['result']['status'], $recommended['result']['status']] === ['not_visible', 'mentioned', 'recommended'], 'visibility integration persists all three states');
    t19VisibilityAssert($recommended['result']['raw_answer'] === 'I recommend Acme GEO.' && $recommended['result']['citations'][0]['url'] === 'https://example.invalid/about', 'complete raw answer and real-provider-shaped citations are preserved');
    t19VisibilityAssert((int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}geo_visibility_results` WHERE `question_id`={$questionA}")->fetchColumn() === 4, 'checks append independent history instead of overwriting');
    $duplicate = $service->check($base + ['idempotency_key' => 'initial-3'], $provider, $contextA);
    t19VisibilityAssert($duplicate['check_id'] === $recommended['check_id'] && (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}geo_visibility_checks`")->fetchColumn() === 6, 'duplicate visibility request is idempotent');
    t19VisibilityReject(
        static fn() => $service->check(array_merge($base, ['idempotency_key' => 'initial-3', 'model_name' => 'different-model']), $provider, $contextA),
        409,
        'an idempotency key cannot silently alias a different visibility request'
    );
    $pdo->prepare("UPDATE `{$prefix}questions` SET `text`='Changed later' WHERE `id`=?")->execute([$questionA]);
    $pdo->prepare("UPDATE `{$prefix}company_profiles` SET `brand`='Changed Brand' WHERE `company_id`=?")->execute([$companyA]);
    $snapshot = $pdo->query("SELECT `question_snapshot`,`brand_snapshot` FROM `{$prefix}geo_visibility_results` WHERE `id`=" . (int) $recommended['result']['id'])->fetch();
    t19VisibilityAssert($snapshot['question_snapshot'] === 'Which GEO service should I choose?' && $snapshot['brand_snapshot'] === 'Acme GEO', 'visibility history freezes the queried question and brand');
    $pdo->prepare("UPDATE `{$prefix}questions` SET `text`='Which GEO service should I choose?' WHERE `id`=?")->execute([$questionA]);
    $pdo->prepare("UPDATE `{$prefix}company_profiles` SET `brand`='Acme GEO' WHERE `company_id`=?")->execute([$companyA]);
    t19VisibilityReject(static fn() => $service->result($recommended['result']['id'], $contextB), 404, 'another tenant cannot read visibility history');

    $blocked = $service->check(
        $base + ['idempotency_key' => 'tool-not-open'],
        new UnavailableVisibilityProvider('responses_web_search', 'ToolNotOpen'),
        $contextA
    );
    t19VisibilityAssert($blocked['status'] === 'failed' && $blocked['error_category'] === 'external_capability_unavailable' && $blocked['external_error_code'] === 'ToolNotOpen' && $blocked['result'] === null, 'ToolNotOpen records a safe failed check without fabricated network result');
    t19VisibilityAssert((int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}geo_visibility_results`")->fetchColumn() === 4, 'external capability failure does not create a visibility result');
    try {
        $service->check($base + ['idempotency_key' => 'unexpected-provider'], new T19UnexpectedVisibilityProvider(), $contextA);
        throw new T19VisibilityFailure('unexpected Provider failure must escape after safe persistence');
    } catch (RuntimeException $expected) {
        if ($expected instanceof T19VisibilityFailure) { throw $expected; }
    }
    $unexpected = $pdo->query("SELECT `status`,`error_category` FROM `{$prefix}geo_visibility_checks` ORDER BY `id` DESC LIMIT 1")->fetch();
    t19VisibilityAssert($unexpected['status'] === 'failed' && $unexpected['error_category'] === 'internal', 'unexpected Provider failure never leaves a visibility check running');
    try {
        $service->check(
            $base + ['idempotency_key' => 'invalid-real-response'],
            new T19FakeVisibilityProvider([new VisibilityResponse('raw real response', [['title' => 'Source', 'url' => 'https://example.invalid/real-source']], ['query_mode' => 'real_network', 'search_query' => 'q'], 'wrong_provider', 'real-req-1')]),
            $contextA
        );
        throw new T19VisibilityFailure('invalid real Provider response must fail provenance validation');
    } catch (GeoException $expected) {
        if ($expected instanceof T19VisibilityFailure) { throw $expected; }
    }
    $invalidReal = $pdo->query("SELECT `status`,`error_category`,`raw_response`,`execution_evidence_json` FROM `{$prefix}geo_visibility_checks` ORDER BY `id` DESC LIMIT 1")->fetch();
    $invalidEvidence = json_decode((string) $invalidReal['execution_evidence_json'], true, 32, JSON_THROW_ON_ERROR);
    t19VisibilityAssert(
        $invalidReal['status'] === 'failed' && $invalidReal['error_category'] === 'invalid_response'
        && $invalidReal['raw_response'] === 'raw real response' && $invalidEvidence['real_network_executed'] === true
        && $invalidEvidence['provider_request_id'] === 'real-req-1',
        'post-query validation failure preserves the raw response and real network request evidence'
    );
    try {
        $service->check($base + ['idempotency_key' => 'invalid-real-response'], new T19UnexpectedVisibilityProvider(), $contextA);
        throw new T19VisibilityFailure('retry Provider failure must escape after preserving earlier evidence');
    } catch (RuntimeException $expected) {
        if ($expected instanceof T19VisibilityFailure) { throw $expected; }
    }
    $retriedEvidence = $pdo->query("SELECT `raw_response`,`response_history_json` FROM `{$prefix}geo_visibility_checks` ORDER BY `id` DESC LIMIT 1")->fetch();
    $responseHistory = json_decode((string) $retriedEvidence['response_history_json'], true, 32, JSON_THROW_ON_ERROR);
    t19VisibilityAssert(
        $retriedEvidence['raw_response'] === 'raw real response'
        && $responseHistory[0]['raw_response'] === 'raw real response'
        && $responseHistory[0]['citations'][0]['url'] === 'https://example.invalid/real-source'
        && $responseHistory[0]['evidence']['real_network_executed'] === true,
        'a later retry failure cannot erase an earlier real response, citation, or network evidence'
    );
    fwrite(STDOUT, "PASS: T19 visibility history integration\n");
} catch (Throwable $exception) { $failure = $exception; }
if ($failure !== null) { fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . PHP_EOL); exit(1); }

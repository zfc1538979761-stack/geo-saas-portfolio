<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\ai\AiGenerationRequest;
use app\common\ai\AiGenerationResult;
use app\common\ai\AiProvider;
use app\common\geo\GeoException;
use app\common\geo\OptimizationSuggestionService;
use app\common\geo\VisibilityClassifier;
use app\common\geo\VisibilityJobRunner;
use app\common\geo\VisibilityPublicationTimeline;
use app\common\geo\VisibilityScheduler;
use app\common\geo\VisibilityService;
use app\common\geo\VisibilityTaskDispatcher;
use app\common\geo\VisibilityTrendService;
use app\common\geo\visibility\VisibilityProvider;
use app\common\geo\visibility\VisibilityRequest;
use app\common\geo\visibility\VisibilityResponse;
use app\common\migration\MigrationManager;
use app\common\job\RetryableJobException;
use app\common\tenant\PdoAuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantResourceCatalog;
use app\common\tenant\TenantTaskEnvelope;
use think\Env;

final class T19ScheduleFailure extends RuntimeException {}
final class T19ScheduleVisibilityProvider implements VisibilityProvider
{
    private array $responses; private int $index = 0;
    public function __construct(array $responses) { $this->responses = $responses; }
    public function providerCode(): string { return 'fake_visibility'; }
    public function query(VisibilityRequest $request): VisibilityResponse { return $this->responses[min($this->index++, count($this->responses) - 1)]; }
}
final class T19SuggestionProvider implements AiProvider
{
    public function providerCode(): string { return 'mock'; }
    public function generate(AiGenerationRequest $request): AiGenerationResult
    {
        return new AiGenerationResult('{"knowledge_gaps":["Add verified comparison facts"],"question_directions":["Clarify the use case"],"answer_directions":["Strengthen factual evidence"],"content_directions":["Publish an evidence-led article"]}', 'mock', 'mock-t19', 'suggest-1', 5, 10, 2);
    }
}
final class T19VisibilityDispatcher implements VisibilityTaskDispatcher
{
    public array $items = [];
    public function dispatch(array $envelope, string $queue): void { $this->items[] = ['envelope' => $envelope, 'queue' => $queue]; }
}
final class T19FlakyVisibilityDispatcher implements VisibilityTaskDispatcher
{
    public array $items = [];
    public int $failures = 1;
    public function dispatch(array $envelope, string $queue): void
    {
        if ($this->failures-- > 0) { throw new RuntimeException('fixture dispatch failure'); }
        $this->items[] = ['envelope' => $envelope, 'queue' => $queue];
    }
}
final class T19FailingVisibilityProvider implements VisibilityProvider
{
    public function providerCode(): string { return 'fake_visibility'; }
    public function query(VisibilityRequest $request): VisibilityResponse { throw new RuntimeException('temporary fixture failure'); }
}
function t19ScheduleAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new T19ScheduleFailure($message); }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}
function t19ScheduleReject(callable $operation, int $status, string $message): void
{
    try { $operation(); } catch (GeoException $exception) { t19ScheduleAssert($exception->httpStatus() === $status, $message); return; }
    throw new T19ScheduleFailure($message);
}

$process = static function (string $name, $fallback) { $value = getenv($name); return $value !== false ? $value : $fallback; };
$host = (string) $process('GEO_DATABASE_HOSTNAME', Env::get('database.hostname', ''));
$port = (int) $process('GEO_DATABASE_HOSTPORT', Env::get('database.hostport', 0));
$database = (string) $process('GEO_DATABASE_DATABASE', Env::get('database.database', ''));
$user = (string) $process('GEO_DATABASE_USERNAME', Env::get('database.username', ''));
$password = (string) $process('GEO_DATABASE_PASSWORD', Env::get('database.password', ''));
if ($host !== '127.0.0.1' || $port === 3306 || preg_match('/_test$/D', $database) !== 1) { fwrite(STDERR, "FAIL: isolated T19 test database required\n"); exit(1); }

$prefix = 't19_schedule_';
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$manager = new MigrationManager($pdo, ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations', $prefix);
$failure = null;
try {
    try { $pdo->exec('DELETE FROM `' . $prefix . 'audit_logs`'); } catch (Throwable $ignored) {}
    $manager->refresh(); $now = 1787328000;
    $pdo->prepare("INSERT INTO `{$prefix}companies` (`name`,`status`,`createtime`,`updatetime`) VALUES ('A','normal',?,?)")->execute([$now, $now]); $companyId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `{$prefix}company_profiles` (`company_id`,`brand`,`industry`,`products_json`,`website`,`introduction`,`contacts_ciphertext`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$companyId, 'Acme GEO', 'Software', '[]', null, 'Verified.', 'x', $now, $now]);
    $pdo->prepare("INSERT INTO `{$prefix}keywords` (`company_id`,`keyword`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?)")->execute([$companyId, 'geo', 'normal', 101, 101, $now, $now]); $keywordId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `{$prefix}questions` (`company_id`,`keyword_id`,`text`,`text_hash`,`source`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([$companyId, $keywordId, 'Which GEO service should I choose?', hash('sha256', 'q', true), 'manual', 'normal', 101, 101, $now, $now]); $questionId = (int) $pdo->lastInsertId();
    $context = TenantContext::enterprise(101, $companyId, 't19_schedule');
    $audit = new PdoAuditLogger($pdo, $prefix); $gateway = new TenantDataGateway($pdo, TenantResourceCatalog::registry($prefix), $audit);
    $clockNow = $now;
    $clock = static function () use (&$clockNow): int { return $clockNow; };
    $timeline = new VisibilityPublicationTimeline($pdo, $prefix);
    $baselineService = new VisibilityService($pdo, $gateway, $audit, new VisibilityClassifier(), $clock, $timeline);
    $baselineIds = [];
    foreach (['m7', 'm3', 'm4', 'mtz', 'm-late-plan', 'm-recover', 'm-dispatch-recovery', 'm-fail', 'm-retry', 'm-expired-lease', 'm-context-bad', 'm-context-good'] as $baselineModel) {
        $baselineCheck = $baselineService->check([
            'question_id' => $questionId, 'platform' => 'p', 'model_name' => $baselineModel,
            'check_type' => 'baseline', 'idempotency_key' => 'baseline:' . $baselineModel,
        ], new T19ScheduleVisibilityProvider([
            new VisibilityResponse('No result.', [], ['query_mode' => 'fake'], 'fake_visibility', 'baseline-' . $baselineModel),
        ]), $context);
        $baselineIds[$baselineModel] = (int) $baselineCheck['result']['id'];
    }
    $pdo->prepare("INSERT INTO `{$prefix}articles` (`company_id`,`keyword_id`,`question_id`,`title`,`status`,`created_by`,`updated_by`,`createtime`,`updatetime`) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$companyId, $keywordId, $questionId, 'Scheduled GEO content', 'published', 101, 101, $now, $now]);
    $articleId = (int) $pdo->lastInsertId();
    $publishedAt = date('Y-m-d H:i:s', $now + 60);
    $pdo->prepare("INSERT INTO `{$prefix}publish_orders` (`order_no`,`company_id`,`article_id`,`article_version_id`,`status`,`total_amount`,`frozen_amount`,`idempotency_key`,`request_fingerprint`,`created_by`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute(['BASELINE-SCHEDULE-ORDER', $companyId, $articleId, 1, 'published', '0.00', '0.00', 'baseline-schedule-order', str_repeat('b', 64), 101, $publishedAt, $publishedAt]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `{$prefix}order_status_logs` (`company_id`,`order_id`,`order_item_id`,`from_status`,`to_status`,`source`,`event_key`,`reason`,`metadata_json`,`created_at`) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$companyId, $orderId, null, 'processing', 'published', 'test', 'baseline-schedule-published', null, '{}', $publishedAt]);
    $clockNow = $now + 120;
    $scheduleStart = date('Y-m-d H:i:s', $clockNow);
    $pdo->prepare("UPDATE `{$prefix}geo_visibility_results` SET `detected_at`=? WHERE `id`=?")
        ->execute([date('Y-m-d H:i:s', $now + 61), $baselineIds['m-late-plan']]);
    $dispatcher = new T19VisibilityDispatcher();
    $envelope = new TenantTaskEnvelope(str_repeat('v', 64), $clock);
    $scheduler = new VisibilityScheduler($pdo, $gateway, $audit, $dispatcher, $envelope, $prefix, $clock, 'geo_visibility', $timeline);
    $createPlan = static function (VisibilityScheduler $target, array $input) use ($baselineIds, $scheduleStart, $context): array {
        $model = (string) ($input['model_name'] ?? '');
        if (isset($baselineIds[$model])) { $input['baseline_result_id'] = $baselineIds[$model]; }
        if (!isset($input['next_run_at'])) { $input['next_run_at'] = $scheduleStart; }
        return $target->createPlan($input, $context);
    };
    $plan7 = $createPlan($scheduler, ['question_id' => $questionId, 'platform' => 'p', 'model_name' => 'm7', 'provider_code' => 'fake_visibility']);
    $plan3 = $createPlan($scheduler, ['question_id' => $questionId, 'platform' => 'p', 'model_name' => 'm3', 'provider_code' => 'fake_visibility', 'interval_days' => 3]);
    $plan4 = $createPlan($scheduler, ['question_id' => $questionId, 'platform' => 'p', 'model_name' => 'm4', 'provider_code' => 'fake_visibility', 'interval_days' => 4]);
    t19ScheduleAssert([$plan7['interval_days'], $plan3['interval_days'], $plan4['interval_days']] === [7, 3, 4], 'visibility plans support default 7 and configurable 3/4 days');
    t19ScheduleReject(static fn() => $createPlan($scheduler, ['question_id' => $questionId, 'platform' => 'p', 'model_name' => 'm5', 'provider_code' => 'x', 'interval_days' => 5]), 422, 'unsupported schedule interval is rejected');
    t19ScheduleReject(static fn() => $createPlan($scheduler, ['question_id' => $questionId, 'platform' => 'p', 'model_name' => 'mtz', 'provider_code' => 'x', 'timezone' => 'UTC']), 422, 'phase-1 rejects a timezone whose absolute trigger semantics are not implemented');
    t19ScheduleReject(static fn() => $scheduler->createPlan(['question_id' => $questionId, 'platform' => 'p', 'model_name' => 'missing-baseline', 'provider_code' => 'fake_visibility'], $context), 422, 'recurring plan cannot be created without an explicit Baseline result');
    t19ScheduleReject(static fn() => $scheduler->createPlan([
        'question_id' => $questionId, 'baseline_result_id' => $baselineIds['m-late-plan'], 'platform' => 'p',
        'model_name' => 'm-late-plan', 'provider_code' => 'fake_visibility', 'next_run_at' => $scheduleStart,
    ], $context), 409, 'recurring phase rejects a Baseline whose detection time is not before first publication');
    t19ScheduleReject(static fn() => $scheduler->createPlan([
        'question_id' => $questionId, 'baseline_result_id' => $baselineIds['m7'], 'platform' => 'p',
        'model_name' => 'm3-mismatch', 'provider_code' => 'fake_visibility', 'next_run_at' => $scheduleStart,
    ], $context), 409, 'Baseline and recurring plan must keep the same question target AI Provider and classifier');
    $ordersBefore = (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}publish_orders`")->fetchColumn();
    $walletsBefore = (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}wallets`")->fetchColumn();
    $scheduled = $scheduler->scheduleDue($clockNow, static fn(array $plan): TenantContext => $context);
    t19ScheduleAssert(count($scheduled) === 3 && count(array_unique(array_column($scheduled, 'job_id'))) === 3, 'due plans create independent geo_async_jobs');
    t19ScheduleAssert(count($dispatcher->items) === 3 && $dispatcher->items[0]['queue'] === 'geo_visibility', 'due visibility jobs are dispatched to the production queue contract');
    $resolvedEnvelope = $envelope->resolve($dispatcher->items[0]['envelope']);
    t19ScheduleAssert($resolvedEnvelope->companyId() === $companyId && ($dispatcher->items[0]['envelope']['task'] ?? '') === 'geo_visibility_check', 'queued visibility work uses a signed tenant envelope');
    t19ScheduleAssert($scheduler->scheduleDue($clockNow, static fn(array $plan): TenantContext => $context) === [], 'same scheduling boundary cannot create duplicate tasks');
    t19ScheduleAssert((int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}geo_async_jobs` WHERE `job_type`='geo_visibility_check'")->fetchColumn() === 3, 'periodic checks reuse the existing async job table');

    $scheduledVisibility = new VisibilityService($pdo, $gateway, $audit, new VisibilityClassifier(), $clock, $timeline);
    $scheduledTrends = new VisibilityTrendService($gateway, $audit, $clock);
    $scheduledSuggestions = new OptimizationSuggestionService($gateway, $audit, $clock);
    $scheduledProvider = new T19ScheduleVisibilityProvider([
        new VisibilityResponse('No result.', [], ['query_mode' => 'fake'], 'fake_visibility', 'scheduled-1'),
        new VisibilityResponse('Acme GEO is present.', [], ['query_mode' => 'fake'], 'fake_visibility', 'scheduled-2'),
    ]);
    $scheduledRunner = new VisibilityJobRunner(
        $pdo, $gateway, $scheduledVisibility, $scheduledTrends, $scheduledSuggestions,
        $scheduledProvider, new T19SuggestionProvider(), $audit, $prefix,
        ['retry_delays' => [0, 0, 0]], $clock
    );
    $scheduledResult = $scheduledRunner->run($scheduled[0]['job_id'], $context);
    t19ScheduleAssert(
        $scheduledResult['status'] === 'succeeded'
        && $scheduledResult['check']['result']['status'] === 'not_visible'
        && ($scheduledResult['comparison']['previous_result_id'] ?? null) === $baselineIds['m7']
        && ($scheduledResult['comparison']['trend'] ?? null) === 'unchanged'
        && ($scheduledResult['suggestion']['status'] ?? null) === 'pending_review',
        'first post-publication job compares from the explicit pre-publication Baseline and creates a reviewable suggestion'
    );
    $scheduledRunner->run($scheduled[0]['job_id'], $context);
    t19ScheduleAssert((int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}geo_visibility_results` WHERE `model_name`='m7'")->fetchColumn() === 2, 'duplicate queue delivery does not execute a scheduled visibility check twice');
    $weekLater = $clockNow + (7 * 86400);
    $weekSlot = date('Y-m-d H:i:s', $weekLater);
    $recoveryPlan = $createPlan($scheduler, ['question_id' => $questionId, 'platform' => 'p', 'model_name' => 'm-recover', 'provider_code' => 'fake_visibility', 'next_run_at' => $weekSlot]);
    $recoveryKey = hash('sha256', $companyId . ':' . $recoveryPlan['id'] . ':' . $weekSlot);
    $recoveryPayload = ['plan_id' => $recoveryPlan['id'], 'question_id' => $questionId, 'platform' => 'p', 'model_name' => 'm-recover', 'provider_code' => 'fake_visibility', 'scheduled_at' => $weekSlot, 'idempotency_key' => 'plan:' . $recoveryPlan['id'] . ':' . $weekSlot];
    $gateway->insert('geo_async_jobs', [
        'actor_id' => 101, 'queue' => 'geo_visibility', 'job_type' => 'geo_visibility_check', 'provider_code' => 'fake_visibility',
        'subject_type' => 'question', 'subject_id' => $questionId, 'payload_json' => json_encode($recoveryPayload, JSON_THROW_ON_ERROR),
        'input_snapshot_json' => json_encode($recoveryPayload, JSON_THROW_ON_ERROR), 'status' => 'queued', 'attempts' => 0,
        'max_attempts' => 3, 'idempotency_key' => $recoveryKey, 'available_at' => $weekSlot, 'reserved_at' => null,
        'started_at' => null, 'lease_expires_at' => null, 'completed_at' => null, 'failed_at' => null, 'cancelled_at' => null,
        'result_article_id' => null, 'result_version_id' => null, 'last_error_code' => null, 'last_error_message' => null,
        'created_at' => $weekSlot, 'updated_at' => $weekSlot,
    ], $context);
    $clockNow = $weekLater;
    $nextScheduled = $scheduler->scheduleDue($weekLater, static fn(array $plan): TenantContext => $context);
    $nextPlan7 = array_values(array_filter($nextScheduled, static fn(array $item): bool => $item['plan_id'] === $plan7['id']))[0];
    $nextResult = $scheduledRunner->run($nextPlan7['job_id'], $context);
    t19ScheduleAssert(
        ($nextResult['comparison']['trend'] ?? null) === 'up'
        && ($nextResult['suggestion']['status'] ?? null) === 'pending_review',
        'periodic recheck automatically compares history and feeds the next optimization round'
    );
    $recoveredPlan = $gateway->find('geo_visibility_plans', $recoveryPlan['id'], $context);
    t19ScheduleAssert((string) $recoveredPlan['next_run_at'] > $weekSlot, 'scheduler recovers an existing slot job and still advances the plan atomically');

    $flakyDispatcher = new T19FlakyVisibilityDispatcher();
    $flakyScheduler = new VisibilityScheduler($pdo, $gateway, $audit, $flakyDispatcher, $envelope, $prefix, $clock, 'geo_visibility', $timeline);
    $dispatchPlan = $createPlan($flakyScheduler, ['question_id' => $questionId, 'platform' => 'p', 'model_name' => 'm-dispatch-recovery', 'provider_code' => 'fake_visibility', 'next_run_at' => $weekSlot]);
    $firstDispatch = $flakyScheduler->scheduleDue($weekLater, static fn(array $plan): TenantContext => $context);
    $pendingOutcome = array_values(array_filter($firstDispatch, static fn(array $item): bool => ($item['status'] ?? '') === 'queued'))[0];
    $dispatchJob = $gateway->find('geo_async_jobs', (int) $pendingOutcome['job_id'], $context);
    t19ScheduleAssert($dispatchJob['status'] === 'queued' && $dispatchJob['reserved_at'] === null, 'dispatch failure leaves a durable pending outbox job');
    $secondDispatch = $flakyScheduler->scheduleDue($weekLater, static fn(array $plan): TenantContext => $context);
    $redriven = array_values(array_filter($secondDispatch, static fn(array $item): bool => (int) $item['job_id'] === (int) $pendingOutcome['job_id'] && ($item['status'] ?? '') === 'dispatched'));
    t19ScheduleAssert(count($redriven) === 1 && count($flakyDispatcher->items) >= 1, 'next scheduler run redrives a committed but undispatched job');

    $failedPlan = $createPlan($scheduler, ['question_id' => $questionId, 'platform' => 'p', 'model_name' => 'm-fail', 'provider_code' => 'fake_visibility', 'next_run_at' => date('Y-m-d H:i:s', $weekLater)]);
    $failedScheduled = $scheduler->scheduleDue($weekLater, static fn(array $plan): TenantContext => $context);
    $failedJob = array_values(array_filter($failedScheduled, static fn(array $item): bool => $item['plan_id'] === $failedPlan['id']))[0];
    $pdo->exec("UPDATE `{$prefix}geo_async_jobs` SET `max_attempts`=1 WHERE `id`=" . (int) $failedJob['job_id']);
    $failingRunner = new VisibilityJobRunner(
        $pdo, $gateway, $scheduledVisibility, $scheduledTrends, $scheduledSuggestions,
        new T19FailingVisibilityProvider(), new T19SuggestionProvider(), $audit, $prefix,
        ['retry_delays' => [0]], $clock
    );
    $failedRun = $failingRunner->run($failedJob['job_id'], $context);
    $failedState = $pdo->query("SELECT `status` FROM `{$prefix}geo_async_jobs` WHERE `id`=" . (int) $failedJob['job_id'])->fetchColumn();
    t19ScheduleAssert($failedRun['status'] === 'failed' && $failedState === 'failed', 'unexpected execution failure is terminal after bounded attempts and never remains running');
    $retryPlan = $createPlan($scheduler, ['question_id' => $questionId, 'platform' => 'p', 'model_name' => 'm-retry', 'provider_code' => 'fake_visibility', 'next_run_at' => date('Y-m-d H:i:s', $weekLater)]);
    $retryScheduled = $scheduler->scheduleDue($weekLater, static fn(array $plan): TenantContext => $context);
    $retryJob = array_values(array_filter($retryScheduled, static fn(array $item): bool => $item['plan_id'] === $retryPlan['id']))[0];
    try {
        $failingRunner->run($retryJob['job_id'], $context);
        throw new T19ScheduleFailure('retryable visibility failure must request a queue retry');
    } catch (RetryableJobException $expected) {
        $retryState = $pdo->query("SELECT `status`,`attempts` FROM `{$prefix}geo_async_jobs` WHERE `id`=" . (int) $retryJob['job_id'])->fetch();
        t19ScheduleAssert($retryState['status'] === 'queued' && (int) $retryState['attempts'] === 1, 'retryable execution failure returns to queued with a bounded attempt count');
    }
    $leasePlan = $createPlan($scheduler, ['question_id' => $questionId, 'platform' => 'p', 'model_name' => 'm-expired-lease', 'provider_code' => 'fake_visibility', 'next_run_at' => $weekSlot]);
    $leaseScheduled = $scheduler->scheduleDue($weekLater, static fn(array $plan): TenantContext => $context);
    $leaseJob = array_values(array_filter($leaseScheduled, static fn(array $item): bool => $item['plan_id'] === $leasePlan['id']))[0];
    $leaseJobRow = $gateway->find('geo_async_jobs', $leaseJob['job_id'], $context);
    $leasePayload = json_decode((string) $leaseJobRow['payload_json'], true, 32, JSON_THROW_ON_ERROR);
    $staleCheckId = $gateway->insert('geo_visibility_checks', [
        'plan_id' => $leasePlan['id'], 'question_id' => $questionId, 'job_id' => $leaseJob['job_id'],
        'question_snapshot' => 'Which GEO service should I choose?', 'brand_snapshot' => 'Acme GEO',
        'check_type' => 'recurring', 'provider_code' => 'fake_visibility', 'platform' => 'p', 'model_name' => 'm-expired-lease',
        'scheduled_at' => $leasePayload['scheduled_at'], 'executed_at' => null, 'status' => 'running',
        'idempotency_key' => hash('sha256', $companyId . ':' . $leasePayload['idempotency_key']),
        'error_category' => null, 'external_error_code' => null, 'error_message' => null,
        'raw_response' => null, 'response_history_json' => null, 'execution_evidence_json' => null,
        'created_at' => $weekSlot, 'updated_at' => $weekSlot,
    ], $context);
    $pdo->exec("UPDATE `{$prefix}geo_async_jobs` SET `status`='running',`attempts`=1,`lease_expires_at`='2026-01-01 00:00:00' WHERE `id`=" . (int) $leaseJob['job_id']);
    $leaseResult = $scheduledRunner->run($leaseJob['job_id'], $context);
    t19ScheduleAssert(
        $leaseResult['status'] === 'succeeded' && $leaseResult['check']['check_id'] === $staleCheckId
        && (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}geo_visibility_checks` WHERE `job_id`=" . (int) $leaseJob['job_id'])->fetchColumn() === 1,
        'an expired running lease resumes its stale running check without duplicating or remaining stuck'
    );
    $isolationTime = $weekLater + (8 * 86400);
    $isolationSlot = date('Y-m-d H:i:s', $isolationTime);
    $badContextPlan = $createPlan($scheduler, ['question_id' => $questionId, 'platform' => 'p', 'model_name' => 'm-context-bad', 'provider_code' => 'fake_visibility', 'next_run_at' => $isolationSlot]);
    $goodContextPlan = $createPlan($scheduler, ['question_id' => $questionId, 'platform' => 'p', 'model_name' => 'm-context-good', 'provider_code' => 'fake_visibility', 'next_run_at' => $isolationSlot]);
    $isolatedSchedule = $scheduler->scheduleDue($isolationTime, static function (array $plan) use ($context): TenantContext {
        if (($plan['model_name'] ?? '') === 'm-context-bad') { throw new RuntimeException('fixture inactive actor'); }
        return $context;
    });
    $badContextOutcome = array_values(array_filter($isolatedSchedule, static fn(array $item): bool => $item['plan_id'] === $badContextPlan['id']))[0];
    $goodContextOutcome = array_values(array_filter($isolatedSchedule, static fn(array $item): bool => $item['plan_id'] === $goodContextPlan['id']))[0];
    t19ScheduleAssert($badContextOutcome['status'] === 'context_unavailable' && $goodContextOutcome['job_id'] > 0, 'one inactive tenant actor does not stop later tenant plans from being scheduled');

    $visibility = new VisibilityService($pdo, $gateway, $audit, new VisibilityClassifier(), $clock, $timeline);
    $provider = new T19ScheduleVisibilityProvider([
        new VisibilityResponse('No result.', [], ['query_mode' => 'fake'], 'fake_visibility', '1'),
        new VisibilityResponse('Acme GEO is present.', [], ['query_mode' => 'fake'], 'fake_visibility', '2'),
        new VisibilityResponse('I recommend Acme GEO.', [], ['query_mode' => 'fake', 'explicit_recommendation' => true, 'recommendation_reason' => 'fit'], 'fake_visibility', '3'),
        new VisibilityResponse('I recommend Acme GEO.', [], ['query_mode' => 'fake', 'explicit_recommendation' => true, 'recommendation_reason' => 'fit'], 'fake_visibility', '4'),
        new VisibilityResponse('Acme GEO is present.', [], ['query_mode' => 'fake'], 'fake_visibility', '5'),
        new VisibilityResponse('Acme GEO is still present.', [], ['query_mode' => 'fake'], 'fake_visibility', '6'),
    ]);
    $results = [];
    for ($i = 1; $i <= 6; $i++) {
        $checked = $visibility->check(['question_id' => $questionId, 'platform' => 'p', 'model_name' => 'trend', 'idempotency_key' => 'trend-' . $i], $provider, $context);
        $results[] = $checked['result'];
    }
    $pdo->prepare("UPDATE `{$prefix}geo_visibility_results` SET `detected_at`=? WHERE `id`=?")->execute([date('Y-m-d H:i:s', $clockNow + 60), $results[4]['id']]);
    $trends = new VisibilityTrendService($gateway, $audit, $clock);
    $up1 = $trends->compare($results[1]['id'], $context);
    $up2 = $trends->compare($results[2]['id'], $context);
    $same = $trends->compare($results[3]['id'], $context);
    $down = $trends->compare($results[4]['id'], $context);
    $same2 = $trends->compare($results[5]['id'], $context);
    t19ScheduleAssert([$up1['trend'], $up2['trend'], $same['trend'], $down['trend'], $same2['trend']] === ['up', 'up', 'unchanged', 'unchanged', 'down'], 'trend comparison recognizes rise unchanged and decline');
    t19ScheduleAssert($same2['previous_result_id'] === $results[3]['id'], 'trend baseline follows detection time instead of a later out-of-order result ID');

    $pdo->prepare("UPDATE `{$prefix}questions` SET `text`='Edited question after detection' WHERE `id`=?")->execute([$questionId]);
    $suggestions = new OptimizationSuggestionService($gateway, $audit, $clock);
    $suggestion = $suggestions->generate($results[5]['id'], new T19SuggestionProvider(), $context);
    $suggestionRow = $pdo->query("SELECT `missed_question`,`generation_basis_json` FROM `{$prefix}geo_optimization_suggestions` WHERE `id`=" . (int) $suggestion['id'])->fetch();
    $suggestionBasis = json_decode((string) $suggestionRow['generation_basis_json'], true, 32, JSON_THROW_ON_ERROR);
    t19ScheduleAssert($suggestion['status'] === 'pending_review' && $suggestion['knowledge_gaps'][0] === 'Add verified comparison facts', 'unmet target creates a reviewable evidence-based optimization suggestion');
    t19ScheduleAssert($suggestionRow['missed_question'] === $results[5]['question_snapshot'] && $suggestionBasis['question'] === $results[5]['question_snapshot'], 'optimization uses the historical detected question snapshot after later edits');
    t19ScheduleReject(static fn() => $suggestions->generate($results[3]['id'], new T19SuggestionProvider(), $context), 409, 'already recommended result does not generate unnecessary optimization');
    t19ScheduleAssert((int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}publish_orders`")->fetchColumn() === $ordersBefore && (int) $pdo->query("SELECT COUNT(*) FROM `{$prefix}wallets`")->fetchColumn() === $walletsBefore, 'scheduling trends and suggestions create no order or wallet side effects');
    fwrite(STDOUT, "PASS: T19 scheduling trend and optimization integration\n");
} catch (Throwable $exception) { $failure = $exception; }
if ($failure !== null) { fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . PHP_EOL); exit(1); }

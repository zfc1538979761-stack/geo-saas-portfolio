<?php

declare(strict_types=1);

namespace app\common\geo;

use app\common\tenant\AuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantTaskEnvelope;
use PDO;
use PDOException;

final class VisibilityScheduler
{
    private PDO $pdo;
    private TenantDataGateway $gateway;
    private AuditLogger $audit;
    private VisibilityTaskDispatcher $dispatcher;
    private TenantTaskEnvelope $envelope;
    private string $prefix;
    private string $queue;
    private ?VisibilityPublicationTimeline $publicationTimeline;
    private $clock;

    public function __construct(
        PDO $pdo,
        TenantDataGateway $gateway,
        AuditLogger $audit,
        VisibilityTaskDispatcher $dispatcher,
        TenantTaskEnvelope $envelope,
        string $prefix,
        ?callable $clock = null,
        string $queue = 'geo_visibility',
        ?VisibilityPublicationTimeline $publicationTimeline = null
    )
    {
        if (preg_match('/^[A-Za-z0-9_]*$/D', $prefix) !== 1) { throw new GeoException('Scheduler table prefix is invalid.', 500); }
        $queue = trim($queue);
        if ($queue === '' || strlen($queue) > 64) { throw new GeoException('Visibility queue is invalid.', 500); }
        $this->pdo = $pdo; $this->gateway = $gateway; $this->audit = $audit;
        $this->dispatcher = $dispatcher; $this->envelope = $envelope; $this->prefix = $prefix; $this->queue = $queue;
        $this->publicationTimeline = $publicationTimeline;
        $this->clock = $clock ?: static fn(): int => time();
    }

    public function createPlan(array $input, TenantContext $context): array
    {
        $questionId = $this->positiveId($input['question_id'] ?? null, 'Question');
        $question = $this->gateway->find('questions', $questionId, $context);
        if (!is_array($question) || $question['deletetime'] !== null || $question['status'] !== 'normal') { throw new GeoException('Enabled schedule question was not found.', 404); }
        $interval = isset($input['interval_days']) ? (int) $input['interval_days'] : 7;
        if (!in_array($interval, [3, 4, 7], true)) { throw new GeoException('Visibility interval must be 3, 4, or 7 days.'); }
        $platform = $this->text($input['platform'] ?? null, 'Platform', 64);
        $model = $this->text($input['model_name'] ?? null, 'Model', 191);
        $provider = $this->text($input['provider_code'] ?? null, 'Provider', 64);
        $baselineResultId = $this->positiveId($input['baseline_result_id'] ?? null, 'Baseline result');
        $timezone = $this->timezone($input['timezone'] ?? 'Asia/Shanghai');
        $now = $this->now();
        $publishedAt = $this->assertBaselineResult(
            $baselineResultId, $questionId, $platform, $model, $provider, $context
        );
        $defaultNextRun = (new \DateTimeImmutable($publishedAt, new \DateTimeZone($timezone)))
            ->modify('+' . $interval . ' days')->format('Y-m-d H:i:s');
        $nextRunAt = (string) ($input['next_run_at'] ?? $defaultNextRun);
        if ($nextRunAt <= $publishedAt) {
            throw new GeoException('Visibility recheck must be scheduled after first publication.', 409);
        }
        try {
            $id = $this->gateway->insert('geo_visibility_plans', [
                'question_id' => $questionId, 'baseline_result_id' => $baselineResultId,
                'platform' => $platform, 'model_name' => $model,
                'provider_code' => $provider, 'interval_days' => $interval,
                'timezone' => $timezone, 'enabled' => 1,
                'next_run_at' => $nextRunAt, 'last_run_at' => null,
                'config_version' => 1, 'created_by' => $context->actorId(), 'updated_by' => $context->actorId(),
                'created_at' => $this->date($now), 'updated_at' => $this->date($now),
            ], $context);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') { throw new GeoException('Visibility plan already exists for this dimension.', 409); }
            throw $exception;
        }
        $this->audit->record('geo_visibility_plan_created', $context, 'geo_visibility_plan', (string) $id, '', [
            'interval_days' => $interval, 'baseline_result_id' => $baselineResultId, 'first_published_at' => $publishedAt,
        ]);
        return $this->present((array) $this->gateway->find('geo_visibility_plans', $id, $context));
    }

    public function updatePlan(int $planId, array $input, TenantContext $context): array
    {
        $plan = $this->gateway->find('geo_visibility_plans', $planId, $context);
        if (!is_array($plan)) { throw new GeoException('Visibility plan was not found.', 404); }
        $changes = [];
        if (array_key_exists('interval_days', $input)) {
            $interval = (int) $input['interval_days'];
            if (!in_array($interval, [3, 4, 7], true)) { throw new GeoException('Visibility interval must be 3, 4, or 7 days.'); }
            $changes['interval_days'] = $interval;
            if (!array_key_exists('next_run_at', $input)) {
                $publishedAt = $this->assertStoredPlanBaseline($plan, $context);
                $anchor = $plan['last_run_at'] === null || $plan['last_run_at'] === ''
                    ? $publishedAt
                    : (string) $plan['last_run_at'];
                $changes['next_run_at'] = (new \DateTimeImmutable($anchor, new \DateTimeZone((string) $plan['timezone'])))
                    ->modify('+' . $interval . ' days')->format('Y-m-d H:i:s');
            }
        }
        if (array_key_exists('enabled', $input)) { $changes['enabled'] = filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0; }
        if (array_key_exists('next_run_at', $input)) {
            $publishedAt = $this->assertStoredPlanBaseline($plan, $context);
            $nextRunAt = (string) $input['next_run_at'];
            if ($nextRunAt <= $publishedAt) { throw new GeoException('Visibility recheck must be scheduled after first publication.', 409); }
            $changes['next_run_at'] = $nextRunAt;
        }
        if ($changes === []) { throw new GeoException('No supported plan changes were supplied.'); }
        $changes['config_version'] = (int) $plan['config_version'] + 1;
        $changes['updated_by'] = $context->actorId(); $changes['updated_at'] = $this->date($this->now());
        $this->gateway->update('geo_visibility_plans', $planId, $changes, $context);
        return $this->present((array) $this->gateway->find('geo_visibility_plans', $planId, $context));
    }

    public function plans(?int $questionId, int $page, int $perPage, TenantContext $context): array
    {
        if ($questionId !== null && $questionId < 1) { throw new GeoException('Question ID is invalid.'); }
        if ($page < 1 || $perPage < 1 || $perPage > 100) { throw new GeoException('Visibility plan pagination is invalid.'); }
        $filters = $questionId === null ? [] : ['question_id' => $questionId];
        if ($questionId !== null && !is_array($this->gateway->find('questions', $questionId, $context))) {
            throw new GeoException('Visibility plan question was not found.', 404);
        }
        $total = $this->gateway->count('geo_visibility_plans', $context, $filters);
        $rows = $this->gateway->list('geo_visibility_plans', $context, $filters, $perPage, ($page - 1) * $perPage);
        return [
            'items' => array_map(fn(array $row): array => $this->present($row), $rows),
            'total' => $total, 'page' => $page, 'per_page' => $perPage,
        ];
    }

    public function scheduleDue(int $timestamp, callable $contextResolver): array
    {
        if ($timestamp < 1) { throw new GeoException('Scheduler timestamp is invalid.'); }
        $dueAt = $this->date($timestamp);
        $statement = $this->pdo->prepare('SELECT * FROM `' . $this->prefix . 'geo_visibility_plans` WHERE `enabled`=1 AND `next_run_at`<=? ORDER BY `next_run_at`,`id`');
        $statement->execute([$dueAt]);
        $created = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $plan) {
            $companyId = (int) $plan['company_id']; $planId = (int) $plan['id'];
            try {
                $context = call_user_func($contextResolver, $plan);
                if (!$context instanceof TenantContext || $context->canBypass() || (int) $context->companyId() !== $companyId) {
                    throw new GeoException('Scheduler tenant context is not trusted for the due plan.', 403);
                }
            } catch (\Throwable $exception) {
                $created[] = ['plan_id' => $planId, 'job_id' => null, 'scheduled_at' => (string) $plan['next_run_at'], 'status' => 'context_unavailable'];
                continue;
            }
            try {
                $this->assertStoredPlanBaseline($plan, $context);
            } catch (\Throwable $exception) {
                $created[] = ['plan_id' => $planId, 'job_id' => null, 'scheduled_at' => (string) $plan['next_run_at'], 'status' => 'baseline_invalid'];
                continue;
            }
            $slot = (string) $plan['next_run_at'];
            $key = hash('sha256', $companyId . ':' . $planId . ':' . $slot);
            $payload = [
                'plan_id' => $planId, 'question_id' => (int) $plan['question_id'],
                'platform' => (string) $plan['platform'], 'model_name' => (string) $plan['model_name'],
                'provider_code' => (string) $plan['provider_code'], 'scheduled_at' => $slot,
                'idempotency_key' => 'plan:' . $planId . ':' . $slot,
            ];
            $jobId = 0;
            $this->pdo->beginTransaction();
            try {
                $lock = $this->pdo->prepare('SELECT `enabled`,`next_run_at`,`interval_days`,`timezone` FROM `' . $this->prefix . 'geo_visibility_plans` WHERE `id`=? AND `company_id`=? FOR UPDATE');
                $lock->execute([$planId, $companyId]);
                $current = $lock->fetch(PDO::FETCH_ASSOC);
                if (!is_array($current) || (int) $current['enabled'] !== 1 || (string) $current['next_run_at'] > $dueAt) {
                    $this->pdo->commit();
                    continue;
                }
                $slot = (string) $current['next_run_at'];
                $key = hash('sha256', $companyId . ':' . $planId . ':' . $slot);
                $payload['scheduled_at'] = $slot;
                $payload['idempotency_key'] = 'plan:' . $planId . ':' . $slot;
                $existing = $this->gateway->list('geo_async_jobs', $context, ['queue' => $this->queue, 'idempotency_key' => $key], 1);
                if ($existing !== []) {
                    $jobId = (int) $existing[0]['id'];
                } else {
                    $jobId = $this->gateway->insert('geo_async_jobs', [
                        'actor_id' => $context->actorId(), 'queue' => $this->queue, 'job_type' => 'geo_visibility_check',
                        'provider_code' => $plan['provider_code'], 'subject_type' => 'question', 'subject_id' => $plan['question_id'],
                        'payload_json' => $this->json($payload), 'input_snapshot_json' => $this->json($payload),
                        'status' => 'queued', 'attempts' => 0, 'max_attempts' => 3, 'idempotency_key' => $key,
                        'available_at' => $slot, 'reserved_at' => null, 'started_at' => null, 'lease_expires_at' => null,
                        'completed_at' => null, 'failed_at' => null, 'cancelled_at' => null,
                        'result_article_id' => null, 'result_version_id' => null, 'last_error_code' => null,
                        'last_error_message' => null, 'created_at' => $dueAt, 'updated_at' => $dueAt,
                    ], $context);
                }
                $next = (new \DateTimeImmutable($slot, new \DateTimeZone((string) $current['timezone'])))
                    ->modify('+' . (int) $current['interval_days'] . ' days')->format('Y-m-d H:i:s');
                $this->gateway->update('geo_visibility_plans', $planId, [
                    'next_run_at' => $next, 'updated_at' => $dueAt,
                ], $context);
                $this->pdo->commit();
            } catch (PDOException $exception) {
                if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                if ($exception->getCode() === '23000') { continue; }
                throw $exception;
            } catch (\Throwable $exception) {
                if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                throw $exception;
            }
        }
        return array_merge($created, $this->dispatchPending($timestamp, $contextResolver));
    }

    private function dispatchPending(int $timestamp, callable $contextResolver): array
    {
        $now = $this->date($timestamp);
        $this->pdo->prepare(
            'UPDATE `' . $this->prefix . 'geo_async_jobs` SET `status`=\'failed\',`failed_at`=?,`lease_expires_at`=NULL,'
            . '`last_error_code`=\'lease_exhausted\',`last_error_message`=\'Visibility job lease expired after its final attempt.\',`updated_at`=?'
            . ' WHERE `queue`=? AND `job_type`=\'geo_visibility_check\' AND `status`=\'running\' AND `lease_expires_at`<=? AND `attempts`>=`max_attempts`'
        )->execute([$now, $now, $this->queue, $now]);
        $this->pdo->prepare(
            'UPDATE `' . $this->prefix . 'geo_async_jobs` SET `status`=\'queued\',`reserved_at`=NULL,`lease_expires_at`=NULL,'
            . '`available_at`=?,`last_error_code`=\'lease_expired\',`last_error_message`=\'Visibility job was recovered after an expired lease.\',`updated_at`=?'
            . ' WHERE `queue`=? AND `job_type`=\'geo_visibility_check\' AND `status`=\'running\' AND `lease_expires_at`<=? AND `attempts`<`max_attempts`'
        )->execute([$now, $now, $this->queue, $now]);

        $statement = $this->pdo->prepare(
            'SELECT * FROM `' . $this->prefix . 'geo_async_jobs` WHERE `queue`=? AND `job_type`=\'geo_visibility_check\''
            . ' AND `status`=\'queued\' AND `available_at`<=? AND `reserved_at` IS NULL ORDER BY `available_at`,`id`'
        );
        $statement->execute([$this->queue, $now]);
        $dispatched = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $job) {
            $payload = [];
            try {
                $payload = json_decode((string) $job['payload_json'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($payload) || (int) ($payload['plan_id'] ?? 0) < 1) { throw new GeoException('Visibility job payload is invalid.', 500); }
                $planQuery = $this->pdo->prepare('SELECT * FROM `' . $this->prefix . 'geo_visibility_plans` WHERE `id`=? AND `company_id`=? LIMIT 1');
                $planQuery->execute([(int) $payload['plan_id'], (int) $job['company_id']]);
                $plan = $planQuery->fetch(PDO::FETCH_ASSOC);
                if (!is_array($plan)) { throw new GeoException('Visibility job plan is unavailable.', 404); }
                $context = call_user_func($contextResolver, $plan);
                if (!$context instanceof TenantContext || $context->canBypass() || (int) $context->companyId() !== (int) $job['company_id']) {
                    throw new GeoException('Scheduler tenant context is not trusted for the pending job.', 403);
                }
            } catch (\Throwable $exception) {
                $dispatched[] = ['plan_id' => (int) ($payload['plan_id'] ?? 0), 'job_id' => (int) $job['id'], 'scheduled_at' => (string) ($payload['scheduled_at'] ?? $job['available_at']), 'status' => 'context_unavailable'];
                continue;
            }
            try {
                $this->dispatcher->dispatch($this->envelope->issue($context, 'geo_visibility_check', ['job_id' => (int) $job['id']]), $this->queue);
                $this->gateway->update('geo_async_jobs', (int) $job['id'], [
                    'reserved_at' => $now, 'last_error_code' => null, 'last_error_message' => null, 'updated_at' => $now,
                ], $context);
                $this->audit->record('geo_visibility_job_dispatched', $context, 'geo_async_job', (string) $job['id'], '', ['plan_id' => (int) $payload['plan_id']]);
                $dispatched[] = ['plan_id' => (int) $payload['plan_id'], 'job_id' => (int) $job['id'], 'scheduled_at' => (string) $payload['scheduled_at'], 'status' => 'dispatched'];
            } catch (\Throwable $exception) {
                $this->gateway->update('geo_async_jobs', (int) $job['id'], [
                    'status' => 'queued', 'reserved_at' => null, 'failed_at' => null, 'last_error_code' => 'dispatch_failed',
                    'last_error_message' => 'Visibility task remains pending for safe redelivery.', 'updated_at' => $now,
                ], $context);
                $this->audit->record('geo_visibility_job_dispatch_failed', $context, 'geo_async_job', (string) $job['id'], '', []);
                $dispatched[] = ['plan_id' => (int) $payload['plan_id'], 'job_id' => (int) $job['id'], 'scheduled_at' => (string) $payload['scheduled_at'], 'status' => 'queued'];
            }
        }
        return $dispatched;
    }

    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'question_id' => (int) $row['question_id'],
            'baseline_result_id' => (int) $row['baseline_result_id'],
            'platform' => (string) $row['platform'], 'model_name' => (string) $row['model_name'],
            'provider_code' => (string) $row['provider_code'], 'interval_days' => (int) $row['interval_days'],
            'timezone' => (string) $row['timezone'], 'enabled' => (bool) $row['enabled'],
            'next_run_at' => (string) $row['next_run_at'], 'last_run_at' => $row['last_run_at'],
            'config_version' => (int) $row['config_version'],
            'created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at'],
        ];
    }
    private function assertStoredPlanBaseline(array $plan, TenantContext $context): string
    {
        return $this->assertBaselineResult(
            (int) ($plan['baseline_result_id'] ?? 0), (int) $plan['question_id'],
            (string) $plan['platform'], (string) $plan['model_name'], (string) $plan['provider_code'], $context
        );
    }
    private function assertBaselineResult(int $resultId, int $questionId, string $platform, string $model, string $provider, TenantContext $context): string
    {
        if ($resultId < 1) { throw new GeoException('A successful pre-publication Baseline result is required.', 409); }
        $result = $this->gateway->find('geo_visibility_results', $resultId, $context);
        if (!is_array($result)) { throw new GeoException('Baseline visibility result was not found.', 404); }
        $check = $this->gateway->find('geo_visibility_checks', (int) $result['check_id'], $context);
        if (!is_array($check) || $check['status'] !== 'succeeded' || $check['check_type'] !== 'baseline'
            || $check['plan_id'] !== null || (int) $result['question_id'] !== $questionId
            || (string) $result['platform'] !== $platform || (string) $result['model_name'] !== $model
            || (string) $check['provider_code'] !== $provider
            || (string) $result['classifier_version'] !== VisibilityService::CLASSIFIER_VERSION) {
            throw new GeoException('Baseline must use the same question, target AI, Provider, and classifier as recurring checks.', 409);
        }
        $publishedAt = $this->publicationTimeline()->firstPublishedAt($questionId, $context);
        if ($publishedAt === null) { throw new GeoException('Published content is required before recurring visibility checks can be planned.', 409); }
        if ((string) $result['detected_at'] >= $publishedAt) {
            throw new GeoException('Baseline visibility detection must predate first publication.', 409);
        }
        return $publishedAt;
    }
    private function publicationTimeline(): VisibilityPublicationTimeline
    {
        if (!$this->publicationTimeline instanceof VisibilityPublicationTimeline) {
            throw new GeoException('Visibility publication timeline is not configured.', 500);
        }
        return $this->publicationTimeline;
    }
    private function positiveId($value, string $label): int { if (!(is_int($value) || (is_string($value) && ctype_digit($value))) || (int) $value < 1) { throw new GeoException($label . ' ID is invalid.'); } return (int) $value; }
    private function text($value, string $label, int $max): string { $value = is_string($value) ? trim($value) : ''; if ($value === '' || strlen($value) > $max) { throw new GeoException($label . ' is invalid.'); } return $value; }
    private function timezone($value): string
    {
        $value = $this->text($value, 'Timezone', 64);
        try { new \DateTimeZone($value); } catch (\Throwable $exception) { throw new GeoException('Timezone is invalid.'); }
        if ($value !== 'Asia/Shanghai') { throw new GeoException('Phase-1 visibility schedules support Asia/Shanghai timezone only.'); }
        return $value;
    }
    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private function now(): int { return (int) call_user_func($this->clock); }
    private function date(int $timestamp): string { return date('Y-m-d H:i:s', $timestamp); }
}

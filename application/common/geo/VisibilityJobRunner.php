<?php

declare(strict_types=1);

namespace app\common\geo;

use app\common\ai\AiProvider;
use app\common\geo\visibility\VisibilityProvider;
use app\common\geo\visibility\VisibilityProviderException;
use app\common\job\RetryableJobException;
use app\common\tenant\AuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use PDO;
use Throwable;

final class VisibilityJobRunner
{
    private PDO $pdo;
    private TenantDataGateway $gateway;
    private VisibilityService $visibility;
    private VisibilityTrendService $trends;
    private OptimizationSuggestionService $suggestions;
    private VisibilityProvider $visibilityProvider;
    private AiProvider $optimizationProvider;
    private AuditLogger $audit;
    private string $prefix;
    private array $config;
    private $clock;

    public function __construct(
        PDO $pdo,
        TenantDataGateway $gateway,
        VisibilityService $visibility,
        VisibilityTrendService $trends,
        OptimizationSuggestionService $suggestions,
        VisibilityProvider $visibilityProvider,
        AiProvider $optimizationProvider,
        AuditLogger $audit,
        string $prefix,
        array $config = [],
        ?callable $clock = null
    ) {
        if (preg_match('/^[A-Za-z0-9_]*$/D', $prefix) !== 1) { throw new GeoException('Visibility runner table prefix is invalid.', 500); }
        $this->pdo = $pdo; $this->gateway = $gateway; $this->visibility = $visibility; $this->trends = $trends;
        $this->suggestions = $suggestions; $this->visibilityProvider = $visibilityProvider;
        $this->optimizationProvider = $optimizationProvider; $this->audit = $audit; $this->prefix = $prefix;
        $this->config = $config; $this->clock = $clock ?: static fn(): int => time();
    }

    public function run(int $jobId, TenantContext $context): array
    {
        $job = $this->claim($jobId, $context);
        if ($job === null) {
            $existing = $this->gateway->find('geo_async_jobs', $jobId, $context);
            if (!is_array($existing) || $existing['job_type'] !== 'geo_visibility_check') { throw new GeoException('Visibility job was not found.', 404); }
            return ['job_id' => $jobId, 'status' => (string) $existing['status'], 'check' => null, 'comparison' => null, 'suggestion' => null];
        }
        $attempt = (int) $job['attempts'];
        $check = null;
        try {
            if ($job['provider_code'] !== $this->visibilityProvider->providerCode()) { throw new GeoException('Visibility job Provider does not match.', 409); }
            $payload = json_decode((string) $job['payload_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) { throw new GeoException('Visibility job payload is invalid.', 500); }
            $check = $this->visibility->check([
                'question_id' => $payload['question_id'] ?? null, 'platform' => $payload['platform'] ?? null,
                'model_name' => $payload['model_name'] ?? null, 'plan_id' => $payload['plan_id'] ?? null,
                'job_id' => $jobId, 'scheduled_at' => $payload['scheduled_at'] ?? $this->date($this->now()),
                'idempotency_key' => $payload['idempotency_key'] ?? ('job:' . $jobId), 'check_type' => 'recurring',
            ], $this->visibilityProvider, $context);
            if ($check['status'] !== 'succeeded' || !is_array($check['result'])) {
                $this->markFailed($job, (string) ($check['error_category'] ?? 'provider_unavailable'), 'Visibility Provider could not execute the scheduled check.', $context);
                $this->touchPlan($payload, $context);
                return ['job_id' => $jobId, 'status' => 'failed', 'check' => $check, 'comparison' => null, 'suggestion' => null];
            }

            $comparison = null;
            try {
                $comparison = $this->trends->compare((int) $check['result']['id'], $context);
            } catch (GeoException $exception) {
                if ($exception->httpStatus() !== 409) { throw $exception; }
            }
            $suggestion = null;
            if ($check['result']['status'] !== 'recommended') {
                $suggestion = $this->suggestions->generate((int) $check['result']['id'], $this->optimizationProvider, $context);
            }
            $finished = $this->date($this->now());
            $this->gateway->update('geo_async_jobs', $jobId, [
                'status' => 'succeeded', 'completed_at' => $finished, 'failed_at' => null,
                'lease_expires_at' => null, 'last_error_code' => null, 'last_error_message' => null,
                'updated_at' => $finished,
            ], $context);
            $this->touchPlan($payload, $context);
            $this->audit->record('geo_visibility_job_succeeded', $context, 'geo_async_job', (string) $jobId, '', [
                'check_id' => $check['check_id'], 'comparison_id' => $comparison['id'] ?? null,
                'suggestion_id' => $suggestion['id'] ?? null,
            ]);
            return ['job_id' => $jobId, 'status' => 'succeeded', 'check' => $check, 'comparison' => $comparison, 'suggestion' => $suggestion];
        } catch (Throwable $exception) {
            $retryable = !($exception instanceof VisibilityProviderException) || $exception->retryable();
            if ($retryable && $attempt < (int) $job['max_attempts']) {
                $delay = $this->retryDelay($attempt); $now = $this->now();
                $this->gateway->update('geo_async_jobs', $jobId, [
                    'status' => 'queued', 'available_at' => $this->date($now + $delay), 'reserved_at' => null, 'lease_expires_at' => null,
                    'last_error_code' => $exception instanceof VisibilityProviderException ? $exception->category() : 'internal',
                    'last_error_message' => 'Visibility job requires a bounded retry.', 'updated_at' => $this->date($now),
                ], $context);
                throw new RetryableJobException('Visibility job requires a bounded retry.', 0, $exception);
            }
            $this->markFailed($job, $exception instanceof VisibilityProviderException ? $exception->category() : 'internal', 'Visibility job failed safely.', $context);
            return ['job_id' => $jobId, 'status' => 'failed', 'check' => $check, 'comparison' => null, 'suggestion' => null];
        }
    }

    private function claim(int $jobId, TenantContext $context): ?array
    {
        if ($jobId < 1) { throw new GeoException('Visibility job ID is invalid.'); }
        $now = $this->now();
        $expired = $this->pdo->prepare(
            'UPDATE `' . $this->prefix . 'geo_async_jobs` SET `status`=\'failed\',`failed_at`=:failed_at,`lease_expires_at`=NULL,'
            . '`last_error_code`=\'lease_exhausted\',`last_error_message`=\'Visibility job lease expired after its final attempt.\',`updated_at`=:updated_at'
            . ' WHERE `id`=:id AND `company_id`=:company_id AND `job_type`=\'geo_visibility_check\' AND `status`=\'running\''
            . ' AND `lease_expires_at`<=:expired_at AND `attempts`>=`max_attempts`'
        );
        $expired->execute([
            'failed_at' => $this->date($now), 'updated_at' => $this->date($now),
            'id' => $jobId, 'company_id' => (int) $context->companyId(), 'expired_at' => $this->date($now),
        ]);
        $statement = $this->pdo->prepare(
            'UPDATE `' . $this->prefix . 'geo_async_jobs` SET `status`=\'running\',`attempts`=`attempts`+1,'
            . '`reserved_at`=:reserved_at,`started_at`=:started_at,`lease_expires_at`=:lease,`updated_at`=:updated_at'
            . ' WHERE `id`=:id AND `company_id`=:company_id AND `job_type`=\'geo_visibility_check\''
            . ' AND `attempts`<`max_attempts` AND ((`status`=\'queued\' AND `available_at`<=:available_at)'
            . ' OR (`status`=\'running\' AND `lease_expires_at`<=:expired_at))'
        );
        $statement->execute([
            'reserved_at' => $this->date($now), 'started_at' => $this->date($now),
            'lease' => $this->date($now + 300), 'updated_at' => $this->date($now), 'available_at' => $this->date($now),
            'expired_at' => $this->date($now), 'id' => $jobId, 'company_id' => (int) $context->companyId(),
        ]);
        if ($statement->rowCount() !== 1) { return null; }
        $row = $this->gateway->find('geo_async_jobs', $jobId, $context);
        return is_array($row) ? $row : null;
    }

    private function markFailed(array $job, string $category, string $message, TenantContext $context): void
    {
        $now = $this->date($this->now());
        $this->gateway->update('geo_async_jobs', (int) $job['id'], [
            'status' => 'failed', 'failed_at' => $now, 'lease_expires_at' => null,
            'last_error_code' => substr($category, 0, 64), 'last_error_message' => substr($message, 0, 255),
            'updated_at' => $now,
        ], $context);
        $this->audit->record('geo_visibility_job_failed', $context, 'geo_async_job', (string) $job['id'], '', ['error_category' => $category]);
    }

    private function touchPlan(array $payload, TenantContext $context): void
    {
        if (!isset($payload['plan_id'])) { return; }
        $plan = $this->gateway->find('geo_visibility_plans', (int) $payload['plan_id'], $context);
        if (!is_array($plan)) { return; }
        $now = $this->date($this->now());
        $this->gateway->update('geo_visibility_plans', (int) $plan['id'], ['last_run_at' => $now, 'updated_at' => $now], $context);
    }

    private function retryDelay(int $attempt): int
    {
        $delays = array_values((array) ($this->config['retry_delays'] ?? [5, 30, 120]));
        return max(0, min(3600, (int) ($delays[max(0, min(count($delays) - 1, $attempt - 1))] ?? 5)));
    }
    private function now(): int { return (int) call_user_func($this->clock); }
    private function date(int $timestamp): string { return date('Y-m-d H:i:s', $timestamp); }
}

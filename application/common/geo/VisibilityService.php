<?php

declare(strict_types=1);

namespace app\common\geo;

use app\common\geo\visibility\VisibilityProvider;
use app\common\geo\visibility\VisibilityProviderException;
use app\common\geo\visibility\VisibilityRequest;
use app\common\geo\visibility\VisibilityResponse;
use app\common\tenant\AuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use PDO;
use PDOException;
use Throwable;

final class VisibilityService
{
    public const CLASSIFIER_VERSION = 't19-phase1-v1';

    private PDO $pdo;
    private TenantDataGateway $gateway;
    private AuditLogger $audit;
    private VisibilityClassifier $classifier;
    private ?VisibilityPublicationTimeline $publicationTimeline;
    private $clock;

    public function __construct(
        PDO $pdo,
        TenantDataGateway $gateway,
        AuditLogger $audit,
        VisibilityClassifier $classifier,
        ?callable $clock = null,
        ?VisibilityPublicationTimeline $publicationTimeline = null
    )
    {
        $this->pdo = $pdo; $this->gateway = $gateway; $this->audit = $audit; $this->classifier = $classifier;
        $this->publicationTimeline = $publicationTimeline;
        $this->clock = $clock ?: static fn(): int => time();
    }

    public function check(array $input, VisibilityProvider $provider, TenantContext $context): array
    {
        $questionId = $this->positiveId($input['question_id'] ?? null, 'Question');
        $platform = $this->text($input['platform'] ?? null, 'Platform', 64);
        $model = $this->text($input['model_name'] ?? null, 'Model', 191);
        $type = (string) ($input['check_type'] ?? 'initial');
        if (!in_array($type, ['initial', 'baseline', 'recurring'], true)) { throw new GeoException('Visibility check type is invalid.'); }
        $rawKey = $this->text($input['idempotency_key'] ?? null, 'Idempotency key', 191);
        $keyMaterial = $type === 'baseline'
            ? implode(':', ['baseline', $questionId, $platform, $model, $provider->providerCode(), self::CLASSIFIER_VERSION])
            : $rawKey;
        $key = hash('sha256', (int) $context->companyId() . ':' . $keyMaterial);
        $planId = isset($input['plan_id']) ? $this->positiveId($input['plan_id'], 'Plan') : null;
        $jobId = isset($input['job_id']) ? $this->positiveId($input['job_id'], 'Job') : null;
        if ($type === 'baseline' && ($planId !== null || $jobId !== null)) {
            throw new GeoException('Baseline visibility checks cannot belong to a post-publication plan or job.', 409);
        }
        $existing = $this->gateway->list('geo_visibility_checks', $context, ['idempotency_key' => $key], 1);
        $checkId = 0;
        $questionText = '';
        $brand = '';
        if ($existing !== []) {
            $this->assertSameRequest($existing[0], $questionId, $platform, $model, $type, $planId, $provider->providerCode());
            $checkId = (int) $existing[0]['id'];
            $questionText = (string) $existing[0]['question_snapshot'];
            $brand = trim((string) $existing[0]['brand_snapshot']);
            if ($brand === '') {
                if ($existing[0]['status'] === 'running') {
                    $failedAt = $this->date($this->now());
                    $this->gateway->update('geo_visibility_checks', $checkId, [
                        'executed_at' => $failedAt, 'status' => 'failed', 'error_category' => 'invalid_request',
                        'external_error_code' => null, 'error_message' => 'Company brand is required for visibility detection.',
                        'updated_at' => $failedAt,
                    ], $context);
                }
                throw new GeoException('Company brand is required for visibility detection.', 409);
            }
            $resumableRunning = $existing[0]['status'] === 'running'
                && $jobId !== null && (int) $existing[0]['job_id'] === $jobId
                && $this->isClaimedVisibilityJob($jobId, $context);
            if (
                $existing[0]['status'] === 'succeeded'
                || ($existing[0]['status'] === 'running' && !$resumableRunning)
                || ($existing[0]['status'] === 'failed' && $existing[0]['error_category'] === 'external_capability_unavailable' && $type !== 'baseline')
            ) {
                return $this->presentCheck($existing[0], $context);
            }
            if ($type === 'baseline') {
                $this->assertBaselineCanStart($questionId, $platform, $model, $provider->providerCode(), $context);
            }
            $this->gateway->update('geo_visibility_checks', $checkId, [
                'status' => 'running', 'executed_at' => null, 'error_category' => null,
                'external_error_code' => null, 'error_message' => null,
                'updated_at' => $this->date($this->now()),
            ], $context);
        } else {
            $question = $this->gateway->find('questions', $questionId, $context);
            if (!is_array($question) || $question['deletetime'] !== null || $question['status'] !== 'normal') { throw new GeoException('Enabled visibility question was not found.', 404); }
            $profiles = $this->gateway->list('company_profiles', $context, [], 1);
            if ($profiles === []) { throw new GeoException('Company profile is required.', 409); }
            $questionText = (string) $question['text'];
            $brand = trim((string) $profiles[0]['brand']);
            if ($brand === '') { throw new GeoException('Company brand is required for visibility detection.', 409); }
            if ($type === 'baseline') {
                $this->assertBaselineCanStart($questionId, $platform, $model, $provider->providerCode(), $context);
            }
        }
        $now = $this->date($this->now());
        if ($checkId === 0) {
            try {
                $checkId = $this->gateway->insert('geo_visibility_checks', [
                    'plan_id' => $planId, 'question_id' => $questionId,
                    'job_id' => $jobId,
                    'question_snapshot' => $questionText, 'brand_snapshot' => $brand,
                    'check_type' => $type, 'provider_code' => $provider->providerCode(), 'platform' => $platform,
                    'model_name' => $model, 'scheduled_at' => (string) ($input['scheduled_at'] ?? $now),
                    'executed_at' => null, 'status' => 'running', 'idempotency_key' => $key,
                    'error_category' => null, 'external_error_code' => null, 'error_message' => null,
                    'raw_response' => null, 'response_history_json' => null, 'execution_evidence_json' => null, 'created_at' => $now, 'updated_at' => $now,
                ], $context);
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000') { throw $exception; }
                $raced = $this->gateway->list('geo_visibility_checks', $context, ['idempotency_key' => $key], 1);
                if ($raced === []) { throw $exception; }
                $this->assertSameRequest($raced[0], $questionId, $platform, $model, $type, $planId, $provider->providerCode());
                return $this->presentCheck($raced[0], $context);
            }
        }
        $request = new VisibilityRequest(
            (int) $context->companyId(), $brand, $questionText, $platform, $model,
            ['profile' => ['brand' => $brand]]
        );
        try {
            $response = $provider->query($request);
        } catch (VisibilityProviderException $exception) {
            $finished = $this->date($this->now());
            $this->gateway->update('geo_visibility_checks', $checkId, [
                'executed_at' => $finished, 'status' => 'failed', 'error_category' => substr($exception->category(), 0, 64),
                'external_error_code' => substr($exception->externalCode(), 0, 64),
                'error_message' => $this->safeProviderMessage($exception),
                'execution_evidence_json' => $this->json([
                    'provider_code' => $exception->providerCode(), 'real_network_executed' => $exception->networkExecuted(),
                    'retryable' => $exception->retryable(),
                ]),
                'updated_at' => $finished,
            ], $context);
            $this->audit->record('geo_visibility_check_failed', $context, 'geo_visibility_check', (string) $checkId, '', ['error_category' => $exception->category(), 'external_error_code' => $exception->externalCode()]);
            if ($exception->retryable()) { throw $exception; }
            return $this->presentCheck((array) $this->gateway->find('geo_visibility_checks', $checkId, $context), $context);
        } catch (Throwable $exception) {
            $finished = $this->date($this->now());
            $this->gateway->update('geo_visibility_checks', $checkId, [
                'executed_at' => $finished, 'status' => 'failed', 'error_category' => 'internal',
                'external_error_code' => null, 'error_message' => 'Visibility Provider failed unexpectedly.',
                'execution_evidence_json' => $this->json(['provider_code' => $provider->providerCode(), 'real_network_executed' => false, 'retryable' => true]),
                'updated_at' => $finished,
            ], $context);
            throw $exception;
        }
        $responseEvidence = $this->responseEvidence($response);
        $responseStoredAt = $this->date($this->now());
        $responseHistory = [[
            'captured_at' => $responseStoredAt, 'raw_response' => $response->rawAnswer(),
            'citations' => $response->citations(), 'evidence' => $responseEvidence,
        ]];
        try {
            $responseHistory = $this->appendResponseHistory($checkId, $response, $responseEvidence, $responseStoredAt, $context);
            $this->gateway->update('geo_visibility_checks', $checkId, [
                'raw_response' => $response->rawAnswer(),
                'response_history_json' => $this->json($responseHistory),
                'execution_evidence_json' => $this->json($responseEvidence),
                'updated_at' => $responseStoredAt,
            ], $context);
        } catch (Throwable $exception) {
            $this->gateway->update('geo_visibility_checks', $checkId, [
                'executed_at' => $responseStoredAt, 'status' => 'failed', 'error_category' => 'persistence',
                'error_message' => 'Visibility response evidence could not be saved safely.',
                'raw_response' => $response->rawAnswer(), 'response_history_json' => $this->json($responseHistory),
                'execution_evidence_json' => $this->json($responseEvidence),
                'updated_at' => $responseStoredAt,
            ], $context);
            throw $exception;
        }
        try {
            if ($response->providerCode() !== $provider->providerCode()) { throw new GeoException('Visibility Provider response provenance is invalid.', 502); }
            $classification = $this->classifier->classify($brand, $response);
        } catch (Throwable $exception) {
            $failedAt = $this->date($this->now());
            $this->gateway->update('geo_visibility_checks', $checkId, [
                'executed_at' => $failedAt, 'status' => 'failed', 'error_category' => 'invalid_response',
                'error_message' => 'Visibility response could not be classified safely.',
                'raw_response' => $response->rawAnswer(), 'response_history_json' => $this->json($responseHistory),
                'execution_evidence_json' => $this->json($responseEvidence),
                'updated_at' => $failedAt,
            ], $context);
            throw $exception;
        }
        $detectedAt = $this->date($this->now());
        if ($type === 'baseline') {
            $publishedAt = $this->publicationTimeline()->firstPublishedAt($questionId, $context);
            if ($publishedAt !== null && $detectedAt >= $publishedAt) {
                $this->gateway->update('geo_visibility_checks', $checkId, [
                    'executed_at' => $detectedAt, 'status' => 'failed', 'error_category' => 'baseline_after_publication',
                    'error_message' => 'Baseline visibility detection did not finish before first publication.',
                    'raw_response' => $response->rawAnswer(), 'response_history_json' => $this->json($responseHistory),
                    'execution_evidence_json' => $this->json($responseEvidence), 'updated_at' => $detectedAt,
                ], $context);
                throw new GeoException('Baseline visibility detection must finish before first publication.', 409);
            }
        }
        $this->pdo->beginTransaction();
        try {
            $resultId = $this->gateway->insert('geo_visibility_results', [
                'check_id' => $checkId, 'question_id' => $questionId,
                'question_snapshot' => $questionText, 'brand_snapshot' => $brand,
                'platform' => $platform, 'model_name' => $model,
                'detected_at' => $detectedAt, 'raw_answer' => $response->rawAnswer(),
                'visibility_status' => $classification['status'], 'brand_mentioned' => $classification['brand_mentioned'] ? 1 : 0,
                'explicitly_recommended' => $classification['explicitly_recommended'] ? 1 : 0,
                'recommendation_reason' => $classification['recommendation_reason'],
                'citations_json' => $this->json($response->citations()), 'evidence_json' => $this->json([
                    'provider_evidence' => $response->evidence(), 'provider_code' => $response->providerCode(),
                    'provider_request_id' => $response->requestId(), 'real_network_executed' => $responseEvidence['real_network_executed'],
                ]),
                'classifier_version' => self::CLASSIFIER_VERSION, 'created_at' => $detectedAt,
            ], $context);
            $this->gateway->update('geo_visibility_checks', $checkId, [
                'executed_at' => $detectedAt, 'status' => 'succeeded',
                'raw_response' => $response->rawAnswer(), 'response_history_json' => $this->json($responseHistory),
                'execution_evidence_json' => $this->json($responseEvidence),
                'updated_at' => $detectedAt,
            ], $context);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            $this->gateway->update('geo_visibility_checks', $checkId, [
                'executed_at' => $detectedAt, 'status' => 'failed', 'error_category' => 'persistence',
                'error_message' => 'Visibility result could not be saved safely.',
                'raw_response' => $response->rawAnswer(), 'response_history_json' => $this->json($responseHistory),
                'execution_evidence_json' => $this->json($responseEvidence),
                'updated_at' => $detectedAt,
            ], $context);
            throw $exception;
        }
        $this->audit->record('geo_visibility_check_succeeded', $context, 'geo_visibility_check', (string) $checkId, '', ['result_id' => $resultId, 'visibility_status' => $classification['status']]);
        return $this->presentCheck((array) $this->gateway->find('geo_visibility_checks', $checkId, $context), $context);
    }

    public function result(int $resultId, TenantContext $context): array
    {
        $row = $this->gateway->find('geo_visibility_results', $resultId, $context);
        if (!is_array($row)) { throw new GeoException('Visibility result was not found.', 404); }
        $check = $this->gateway->find('geo_visibility_checks', (int) $row['check_id'], $context);
        return $this->presentResult($row, is_array($check) ? $check : null);
    }

    public function history(array $filters, int $page, int $perPage, TenantContext $context): array
    {
        $questionId = $this->positiveId($filters['question_id'] ?? null, 'Question');
        if (!is_array($this->gateway->find('questions', $questionId, $context))) {
            throw new GeoException('Visibility question was not found.', 404);
        }
        if ($page < 1 || $perPage < 1 || $perPage > 100) { throw new GeoException('Visibility history pagination is invalid.'); }
        $query = ['question_id' => $questionId];
        foreach (['platform', 'model_name'] as $field) {
            if (isset($filters[$field])) { $query[$field] = $this->text($filters[$field], ucfirst(str_replace('_', ' ', $field)), $field === 'platform' ? 64 : 191); }
        }
        $total = $this->gateway->count('geo_visibility_results', $context, $query);
        $rows = $this->gateway->list('geo_visibility_results', $context, $query, $perPage, ($page - 1) * $perPage);
        $items = [];
        foreach ($rows as $row) {
            $check = $this->gateway->find('geo_visibility_checks', (int) $row['check_id'], $context);
            $items[] = $this->presentResult($row, is_array($check) ? $check : null);
        }
        $baselineQuery = $query;
        $baselineQuery['check_type'] = 'baseline';
        $baselineTotal = $this->gateway->count('geo_visibility_checks', $context, $baselineQuery);
        $latestBaselineRows = $baselineTotal > 0
            ? $this->gateway->list('geo_visibility_checks', $context, $baselineQuery, 1, $baselineTotal - 1)
            : [];
        $latestBaselineCheck = $latestBaselineRows === []
            ? null
            : $this->presentCheck($latestBaselineRows[0], $context);
        return [
            'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage,
            'latest_baseline_check' => $latestBaselineCheck,
        ];
    }

    private function presentCheck(array $row, TenantContext $context): array
    {
        $results = $this->gateway->list('geo_visibility_results', $context, ['check_id' => (int) $row['id']], 1);
        return [
            'check_id' => (int) $row['id'], 'status' => (string) $row['status'],
            'check_type' => (string) $row['check_type'],
            'plan_id' => $row['plan_id'] === null ? null : (int) $row['plan_id'],
            'question_id' => (int) $row['question_id'],
            'provider_code' => (string) $row['provider_code'],
            'platform' => (string) $row['platform'], 'model_name' => (string) $row['model_name'],
            'scheduled_at' => (string) $row['scheduled_at'], 'executed_at' => $row['executed_at'],
            'error_category' => $row['error_category'], 'external_error_code' => $row['external_error_code'],
            'error_message' => $row['error_message'],
            'raw_response' => $row['raw_response'],
            'response_history' => $row['response_history_json'] === null ? [] : json_decode((string) $row['response_history_json'], true, 64, JSON_THROW_ON_ERROR),
            'execution_evidence' => $row['execution_evidence_json'] === null ? null : json_decode((string) $row['execution_evidence_json'], true, 64, JSON_THROW_ON_ERROR),
            'result' => $results === [] ? null : $this->presentResult($results[0], $row),
        ];
    }

    private function presentResult(array $row, ?array $check = null): array
    {
        return [
            'id' => (int) $row['id'], 'check_id' => (int) $row['check_id'], 'question_id' => (int) $row['question_id'],
            'question_snapshot' => (string) $row['question_snapshot'], 'brand_snapshot' => (string) $row['brand_snapshot'],
            'platform' => (string) $row['platform'], 'model_name' => (string) $row['model_name'],
            'detected_at' => (string) $row['detected_at'], 'raw_answer' => (string) $row['raw_answer'],
            'status' => (string) $row['visibility_status'], 'recommendation_reason' => $row['recommendation_reason'],
            'check_type' => is_array($check) ? (string) $check['check_type'] : null,
            'plan_id' => is_array($check) && $check['plan_id'] !== null ? (int) $check['plan_id'] : null,
            'provider_code' => is_array($check) ? (string) $check['provider_code'] : null,
            'citations' => json_decode((string) $row['citations_json'], true, 64, JSON_THROW_ON_ERROR),
            'evidence' => json_decode((string) $row['evidence_json'], true, 64, JSON_THROW_ON_ERROR),
        ];
    }

    private function positiveId($value, string $label): int
    {
        if (!(is_int($value) || (is_string($value) && ctype_digit($value))) || (int) $value < 1) { throw new GeoException($label . ' ID is invalid.'); }
        return (int) $value;
    }
    private function text($value, string $label, int $max): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || strlen($value) > $max) { throw new GeoException($label . ' is invalid.'); }
        return $value;
    }
    private function assertSameRequest(array $row, int $questionId, string $platform, string $model, string $type, ?int $planId, string $provider): void
    {
        if ((int) $row['question_id'] !== $questionId || (string) $row['platform'] !== $platform
            || (string) $row['model_name'] !== $model || (string) $row['check_type'] !== $type
            || ($row['plan_id'] === null ? null : (int) $row['plan_id']) !== $planId
            || (string) $row['provider_code'] !== $provider) {
            throw new GeoException('Visibility idempotency key belongs to a different request.', 409);
        }
    }
    private function safeProviderMessage(VisibilityProviderException $exception): string
    {
        $messages = [
            'external_capability_unavailable' => 'External visibility capability is not enabled.',
            'rate_limit' => 'Visibility Provider rate limit was reached.',
            'timeout' => 'Visibility Provider request timed out.',
            'server' => 'Visibility Provider is temporarily unavailable.',
            'configuration' => 'Visibility Provider configuration is invalid.',
        ];
        return $messages[$exception->category()] ?? 'Visibility Provider could not execute the query.';
    }
    private function responseEvidence(VisibilityResponse $response): array
    {
        return [
            'provider_code' => $response->providerCode(),
            'provider_request_id' => $response->requestId(),
            'real_network_executed' => ($response->evidence()['query_mode'] ?? '') === 'real_network',
            'provider_evidence' => $response->evidence(),
            'retryable' => false,
        ];
    }
    private function appendResponseHistory(int $checkId, VisibilityResponse $response, array $evidence, string $capturedAt, TenantContext $context): array
    {
        $row = $this->gateway->find('geo_visibility_checks', $checkId, $context);
        if (!is_array($row)) { throw new GeoException('Visibility check disappeared before response persistence.', 409); }
        $history = $row['response_history_json'] === null
            ? []
            : json_decode((string) $row['response_history_json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($history)) { throw new GeoException('Visibility response history is invalid.', 500); }
        $history[] = [
            'captured_at' => $capturedAt,
            'raw_response' => $response->rawAnswer(),
            'citations' => $response->citations(),
            'evidence' => $evidence,
        ];
        return $history;
    }
    private function isClaimedVisibilityJob(int $jobId, TenantContext $context): bool
    {
        $job = $this->gateway->find('geo_async_jobs', $jobId, $context);
        return is_array($job) && $job['job_type'] === 'geo_visibility_check' && $job['status'] === 'running';
    }
    private function assertBaselineCanStart(int $questionId, string $platform, string $model, string $provider, TenantContext $context): void
    {
        $timeline = $this->publicationTimeline();
        if ($timeline->firstPublishedAt($questionId, $context) !== null) {
            throw new GeoException('A new Baseline cannot be created after the corresponding content was first published.', 409);
        }
        if ($timeline->successfulBaselineResultId($questionId, $platform, $model, $provider, self::CLASSIFIER_VERSION, $context) !== null) {
            throw new GeoException('A successful Baseline already exists for this visibility dimension.', 409);
        }
    }
    private function publicationTimeline(): VisibilityPublicationTimeline
    {
        if (!$this->publicationTimeline instanceof VisibilityPublicationTimeline) {
            throw new GeoException('Baseline publication timeline is not configured.', 500);
        }
        return $this->publicationTimeline;
    }
    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private function now(): int { return (int) call_user_func($this->clock); }
    private function date(int $timestamp): string { return date('Y-m-d H:i:s', $timestamp); }
}

<?php

declare(strict_types=1);

namespace app\common\ai\task;

use app\common\tenant\AuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use app\common\tenant\TenantTaskEnvelope;
use PDO;
use PDOException;
use Throwable;

final class AiGenerationService
{
    private PDO $pdo;
    private TenantDataGateway $gateway;
    private AuditLogger $audit;
    private AiTaskDispatcher $dispatcher;
    private TenantTaskEnvelope $envelopes;
    private string $prefix;
    private array $config;
    private $clock;

    public function __construct(
        PDO $pdo,
        TenantDataGateway $gateway,
        AuditLogger $audit,
        AiTaskDispatcher $dispatcher,
        TenantTaskEnvelope $envelopes,
        string $prefix,
        array $config,
        ?callable $clock = null
    ) {
        if (preg_match('/^[A-Za-z0-9_]*$/D', $prefix) !== 1) { throw new AiTaskException('Database prefix is invalid.', 500); }
        $this->pdo = $pdo; $this->gateway = $gateway; $this->audit = $audit;
        $this->dispatcher = $dispatcher; $this->envelopes = $envelopes;
        $this->prefix = $prefix; $this->config = $config;
        $this->clock = $clock ?: static fn(): int => time();
    }

    public function create(array $input, string $idempotencyKey, TenantContext $context, string $ip = ''): array
    {
        $allowed = ['keyword_id', 'question_id', 'prompt_template_id', 'knowledge_item_ids', 'article_id', 'current_version_id', 'geo_answer_version_id'];
        if (array_diff(array_keys($input), $allowed) !== []) { throw new AiTaskException('AI task input contains unsupported fields.'); }
        $key = trim($idempotencyKey);
        if ($key === '' || strlen($key) > 191) { throw new AiTaskException('Idempotency-Key is required.'); }
        $keywordId = $this->positiveId($input['keyword_id'] ?? null, 'Keyword');
        $questionId = $this->positiveId($input['question_id'] ?? null, 'Question');
        $promptId = $this->positiveId($input['prompt_template_id'] ?? null, 'Prompt version');
        $knowledgeIds = $this->ids($input['knowledge_item_ids'] ?? []);
        $articleId = isset($input['article_id']) ? $this->positiveId($input['article_id'], 'Article') : 0;
        $expectedVersion = isset($input['current_version_id']) ? $this->positiveId($input['current_version_id'], 'Current version') : 0;
        $geoAnswerVersionId = isset($input['geo_answer_version_id']) ? $this->positiveId($input['geo_answer_version_id'], 'GEO answer version') : 0;
        if (($articleId > 0) !== ($expectedVersion > 0)) { throw new AiTaskException('Article and current version must be supplied together.'); }
        $companyId = (int) $context->companyId();
        $queue = trim((string) ($this->config['queue'] ?? 'geo_ai_generation'));
        $hashedKey = hash('sha256', $companyId . ':' . $key);
        $existing = $this->findByIdempotency($queue, $hashedKey, $context);
        if ($existing !== null) {
            $this->assertSameIdempotentRequest($existing, $keywordId, $questionId, $promptId, $knowledgeIds, $articleId, $expectedVersion, $geoAnswerVersionId);
            return $this->present($existing);
        }

        $this->pdo->beginTransaction();
        try {
            $keyword = $this->lockedOne('keywords', $keywordId, $context, ['status' => 'normal', 'deletetime' => null]);
            $question = $this->lockedOne('questions', $questionId, $context, ['keyword_id' => $keywordId, 'status' => 'normal', 'deletetime' => null]);
            $prompt = $this->lockedOne('prompt_templates', $promptId, $context, ['status' => 'normal', 'deletetime' => null]);
            $knowledge = $knowledgeIds === [] ? [] : $this->gateway->findMany(
                'knowledge_items', $knowledgeIds, $context, ['type' => 'text', 'status' => 'normal', 'deletetime' => null], true, $ip
            );
            if (!is_array($knowledge)) { throw new AiTaskException('Only active text knowledge can be used.', 404); }
            $target = ['article_id' => null, 'expected_current_version_id' => null];
            if ($articleId > 0) {
                $article = $this->lockedOne('articles', $articleId, $context, ['deletetime' => null]);
                if ((int) $article['keyword_id'] !== $keywordId || (int) $article['question_id'] !== $questionId || (int) $article['current_version_id'] !== $expectedVersion) {
                    throw new AiTaskException('Article target does not match the requested context.', 409);
                }
                $target = ['article_id' => $articleId, 'expected_current_version_id' => $expectedVersion];
            }
            $geoTargetAnswer = [];
            if ($geoAnswerVersionId > 0) {
                $answer = $this->lockedOne('geo_answer_versions', $geoAnswerVersionId, $context, [
                    'question_id' => $questionId, 'keyword_id' => $keywordId, 'status' => 'confirmed',
                ]);
                $geoTargetAnswer = [
                    'id' => (int) $answer['id'], 'version_no' => (int) $answer['version_no'],
                    'answer' => (string) $answer['answer_text'], 'recommendation_reason' => $answer['recommendation_reason'],
                    'facts' => json_decode((string) $answer['facts_json'], true, 64, JSON_THROW_ON_ERROR),
                    'evidence' => json_decode((string) $answer['evidence_json'], true, 64, JSON_THROW_ON_ERROR),
                    'profile_snapshot' => json_decode((string) $answer['profile_snapshot_json'], true, 64, JSON_THROW_ON_ERROR),
                    'knowledge_snapshot' => json_decode((string) $answer['knowledge_snapshot_json'], true, 64, JSON_THROW_ON_ERROR),
                ];
            }
            $snapshot = $this->snapshot($context, $keyword, $question, $prompt, $knowledge, $target, $geoTargetAnswer);
            $now = $this->date($this->now());
            $jobId = $this->gateway->insert('geo_async_jobs', [
                'actor_id' => $context->actorId(), 'queue' => $queue, 'job_type' => 'ai_article_generation',
                'provider_code' => trim((string) ($this->config['provider'] ?? '')),
                'subject_type' => $articleId > 0 ? 'article' : 'question', 'subject_id' => $articleId > 0 ? $articleId : $questionId,
                'payload_json' => '{}',
                'input_snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'status' => 'queued', 'attempts' => 0,
                'max_attempts' => max(1, min(10, (int) ($this->config['max_attempts'] ?? 3))),
                'idempotency_key' => $hashedKey, 'available_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ], $context);
            $this->gateway->update('geo_async_jobs', $jobId, [
                'payload_json' => json_encode(['job_id' => $jobId], JSON_THROW_ON_ERROR),
            ], $context);
            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($exception->getCode() === '23000') {
                $existing = $this->findByIdempotency($queue, $hashedKey, $context);
                if ($existing !== null) {
                    $this->assertSameIdempotentRequest($existing, $keywordId, $questionId, $promptId, $knowledgeIds, $articleId, $expectedVersion, $geoAnswerVersionId);
                    return $this->present($existing);
                }
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
        $envelope = $this->envelopes->issue($context, 'ai_article_generation', ['job_id' => $jobId]);
        try {
            $this->dispatcher->dispatch($envelope, $queue);
        } catch (Throwable $exception) {
            $failedAt = $this->date($this->now());
            $this->gateway->update('geo_async_jobs', $jobId, [
                'status' => 'failed', 'failed_at' => $failedAt, 'last_error_code' => 'dispatch_failed',
                'last_error_message' => 'AI task could not be queued.', 'updated_at' => $failedAt,
            ], $context);
        }
        $this->audit->record('ai_generation_task_created', $context, 'ai_job', (string) $jobId, $ip, [
            'provider_code' => (string) ($this->config['provider'] ?? ''), 'keyword_id' => $keywordId, 'question_id' => $questionId,
        ]);
        return $this->detail($jobId, $context, $ip);
    }

    public function detail(int $jobId, TenantContext $context, string $ip = ''): array
    {
        $row = $this->gateway->find('geo_async_jobs', $this->positiveId($jobId, 'Job'), $context, $ip);
        if (!is_array($row) || $row['job_type'] !== 'ai_article_generation') { throw new AiTaskException('AI task was not found.', 404); }
        return $this->present($row);
    }

    public function result(int $jobId, TenantContext $context, string $ip = ''): array
    {
        $task = $this->detail($jobId, $context, $ip);
        if ($task['status'] !== 'succeeded') { throw new AiTaskException('AI task result is not available.', 409); }
        $article = $this->gateway->find('articles', (int) $task['article_id'], $context, $ip);
        $version = $this->gateway->find('article_versions', (int) $task['version_id'], $context, $ip);
        if (!is_array($article) || !is_array($version)) { throw new AiTaskException('AI task result is unavailable.', 500); }
        return ['task' => $task, 'article' => [
            'id' => (int) $article['id'], 'title' => $article['title'], 'current_version_id' => (int) $article['current_version_id'],
        ], 'version' => [
            'id' => (int) $version['id'], 'version_no' => (int) $version['version_no'],
            'title' => $version['title_snapshot'], 'content' => $version['content'], 'source' => $version['source'],
        ]];
    }

    public function retry(int $jobId, TenantContext $context, string $ip = ''): array
    {
        $row = $this->gateway->find('geo_async_jobs', $this->positiveId($jobId, 'Job'), $context, $ip);
        if (!is_array($row) || $row['job_type'] !== 'ai_article_generation') { throw new AiTaskException('AI task was not found.', 404); }
        if ($row['status'] !== 'failed') { throw new AiTaskException('Only failed AI tasks can be retried.', 409); }
        $now = $this->date($this->now());
        $additionalBudget = max(1, min(10, (int) ($this->config['max_attempts'] ?? 3)));
        $this->gateway->update('geo_async_jobs', $jobId, [
            'status' => 'queued', 'max_attempts' => (int) $row['attempts'] + $additionalBudget,
            'available_at' => $now, 'reserved_at' => null,
            'started_at' => null, 'lease_expires_at' => null, 'failed_at' => null,
            'last_error_code' => null, 'last_error_message' => null, 'updated_at' => $now,
        ], $context, $ip);
        $this->dispatcher->dispatch($this->envelopes->issue($context, 'ai_article_generation', ['job_id' => $jobId]), (string) $row['queue']);
        $this->audit->record('ai_generation_task_retried', $context, 'ai_job', (string) $jobId, $ip);
        return $this->detail($jobId, $context, $ip);
    }

    public function cancel(int $jobId, TenantContext $context, string $ip = ''): array
    {
        $row = $this->gateway->find('geo_async_jobs', $this->positiveId($jobId, 'Job'), $context, $ip);
        if (!is_array($row) || $row['job_type'] !== 'ai_article_generation') { throw new AiTaskException('AI task was not found.', 404); }
        if ($row['status'] === 'cancelled') { return $this->present($row); }
        if ($row['status'] !== 'queued') { throw new AiTaskException('Only queued AI tasks can be cancelled.', 409); }
        $now = $this->date($this->now());
        $this->gateway->update('geo_async_jobs', $jobId, [
            'status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now,
        ], $context, $ip);
        $this->audit->record('ai_generation_task_cancelled', $context, 'ai_job', (string) $jobId, $ip);
        return $this->detail($jobId, $context, $ip);
    }

    private function snapshot(TenantContext $context, array $keyword, array $question, array $prompt, array $knowledge, array $target, array $geoTargetAnswer = []): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.`name`,p.`brand`,p.`industry`,p.`products_json`,p.`website`,p.`introduction`'
            . ' FROM `' . $this->prefix . 'companies` c LEFT JOIN `' . $this->prefix . 'company_profiles` p'
            . ' ON p.`company_id`=c.`id` WHERE c.`id`=:company_id LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['company_id' => (int) $context->companyId()]);
        $company = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($company)) { throw new AiTaskException('Company context is unavailable.', 404); }
        $products = [];
        if (is_string($company['products_json'] ?? null) && $company['products_json'] !== '') {
            $decoded = json_decode($company['products_json'], true);
            $products = is_array($decoded) ? $decoded : [];
        }
        $items = array_map(static function (array $item): array {
            return [
                'id' => (int) $item['id'], 'type' => 'text', 'title' => (string) $item['title'],
                'status' => (string) $item['status'], 'content' => (string) $item['content'],
                'content_sha256' => hash('sha256', (string) $item['content']),
            ];
        }, $knowledge);
        return [
            'schema_version' => 1,
            'company' => ['name' => $company['name'], 'brand' => $company['brand'], 'industry' => $company['industry'], 'products_services' => $products, 'website' => $company['website'], 'introduction' => $company['introduction']],
            'keyword' => ['id' => (int) $keyword['id'], 'text' => $keyword['keyword']],
            'question' => ['id' => (int) $question['id'], 'text' => $question['text']],
            'prompt' => ['id' => (int) $prompt['id'], 'logical_key' => $prompt['logical_key'], 'version_no' => (int) $prompt['version_no'], 'name' => $prompt['name'], 'scene' => $prompt['scene'], 'content' => $prompt['content']],
            'knowledge' => $items, 'target' => $target, 'geo_target_answer' => $geoTargetAnswer,
        ];
    }

    private function lockedOne(string $resource, int $id, TenantContext $context, array $filters): array
    {
        $rows = $this->gateway->findMany($resource, [$id], $context, $filters, true);
        if (!is_array($rows)) { throw new AiTaskException('Requested AI generation context is unavailable.', 404); }
        return $rows[0];
    }

    private function findByIdempotency(string $queue, string $key, TenantContext $context): ?array
    {
        $rows = $this->gateway->list('geo_async_jobs', $context, ['queue' => $queue, 'idempotency_key' => $key], 1, 0);
        return $rows[0] ?? null;
    }

    private function assertSameIdempotentRequest(array $job, int $keywordId, int $questionId, int $promptId, array $knowledgeIds, int $articleId, int $expectedVersion, int $geoAnswerVersionId): void
    {
        try { $snapshot = json_decode((string) $job['input_snapshot_json'], true, 64, JSON_THROW_ON_ERROR); }
        catch (Throwable $exception) { throw new AiTaskException('Existing idempotent task is invalid.', 500); }
        $storedKnowledge = array_map(static fn(array $item): int => (int) ($item['id'] ?? 0), (array) ($snapshot['knowledge'] ?? []));
        sort($storedKnowledge, SORT_NUMERIC);
        $target = (array) ($snapshot['target'] ?? []);
        if (
            (int) ($snapshot['keyword']['id'] ?? 0) !== $keywordId
            || (int) ($snapshot['question']['id'] ?? 0) !== $questionId
            || (int) ($snapshot['prompt']['id'] ?? 0) !== $promptId
            || $storedKnowledge !== $knowledgeIds
            || (int) ($target['article_id'] ?? 0) !== $articleId
            || (int) ($target['expected_current_version_id'] ?? 0) !== $expectedVersion
            || (int) ($snapshot['geo_target_answer']['id'] ?? 0) !== $geoAnswerVersionId
        ) { throw new AiTaskException('Idempotency-Key was already used for a different request.', 409); }
    }

    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'status' => $row['status'], 'provider_code' => $row['provider_code'],
            'attempts' => (int) $row['attempts'], 'max_attempts' => (int) $row['max_attempts'],
            'article_id' => $row['result_article_id'] === null ? null : (int) $row['result_article_id'],
            'version_id' => $row['result_version_id'] === null ? null : (int) $row['result_version_id'],
            'error' => $row['status'] === 'failed' ? ['code' => $row['last_error_code'], 'message' => $row['last_error_message']] : null,
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
        ];
    }

    private function positiveId($value, string $name): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) { throw new AiTaskException($name . ' ID is invalid.'); }
        return (int) $id;
    }

    private function ids($value): array
    {
        if (!is_array($value) || count($value) > 50) { throw new AiTaskException('Knowledge item IDs are invalid.'); }
        $ids = [];
        foreach ($value as $item) { $ids[] = $this->positiveId($item, 'Knowledge item'); }
        $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC); return $ids;
    }

    private function now(): int { return (int) call_user_func($this->clock); }
    private function date(int $timestamp): string { return date('Y-m-d H:i:s', $timestamp); }
}

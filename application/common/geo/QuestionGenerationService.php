<?php

declare(strict_types=1);

namespace app\common\geo;

use app\common\ai\AiGenerationRequest;
use app\common\ai\AiProvider;
use app\common\content\ContentTextNormalizer;
use app\common\tenant\AuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use PDO;
use PDOException;
use Throwable;

final class QuestionGenerationService
{
    private PDO $pdo;
    private TenantDataGateway $gateway;
    private AuditLogger $audit;
    private ContentTextNormalizer $normalizer;
    private $clock;

    public function __construct(PDO $pdo, TenantDataGateway $gateway, AuditLogger $audit, ?callable $clock = null)
    {
        $this->pdo = $pdo;
        $this->gateway = $gateway;
        $this->audit = $audit;
        $this->normalizer = new ContentTextNormalizer();
        $this->clock = $clock ?: static fn(): int => time();
    }

    public function generate(array $input, AiProvider $provider, TenantContext $context): array
    {
        $keywordId = $this->positiveId($input['keyword_id'] ?? null, 'Keyword');
        $knowledgeIds = $this->ids($input['knowledge_item_ids'] ?? []);
        $keyword = $this->gateway->find('keywords', $keywordId, $context);
        if (!is_array($keyword) || $keyword['deletetime'] !== null || $keyword['status'] !== 'normal') {
            throw new GeoException('Enabled keyword was not found.', 404);
        }
        [$profile, $knowledge] = $this->evidence($knowledgeIds, $context);
        $snapshot = ['profile' => $profile, 'keyword' => ['id' => $keywordId, 'keyword' => $keyword['keyword']], 'knowledge' => $knowledge];
        $result = $provider->generate(new AiGenerationRequest(
            'Generate realistic user questions. Return strict JSON: {"questions":["..."]}. Do not add facts.',
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            2048
        ));
        $decoded = json_decode($result->rawText(), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !isset($decoded['questions']) || !is_array($decoded['questions']) || $decoded['questions'] === []) {
            throw new GeoException('AI question output is invalid.', 422);
        }

        $now = $this->now();
        $this->pdo->beginTransaction();
        try {
            $batchId = $this->gateway->insert('geo_question_generation_batches', [
                'job_id' => null, 'ai_call_id' => null, 'provider_code' => $result->providerCode(),
                'model_name' => $result->modelName(), 'provider_request_id' => $result->requestId(),
                'input_snapshot_json' => $this->json($snapshot), 'generated_count' => 0,
                'duplicate_count' => 0, 'status' => 'running', 'error_category' => null,
                'created_by' => $context->actorId(), 'created_at' => $this->date($now), 'completed_at' => null,
            ], $context);
            $created = [];
            $duplicates = 0;
            $seen = [];
            foreach ($decoded['questions'] as $candidate) {
                $text = $this->normalizer->question($candidate);
                $digest = $this->normalizer->questionDigest($text);
                $key = bin2hex($digest);
                if (isset($seen[$key]) || $this->questionExists($context, $keywordId, $digest)) {
                    $duplicates++;
                    continue;
                }
                $seen[$key] = true;
                try {
                    $id = $this->gateway->insert('questions', [
                        'keyword_id' => $keywordId, 'text' => $text, 'text_hash' => $digest,
                        'source' => 'ai', 'generation_batch_id' => $batchId, 'status' => 'pending_review',
                        'reviewed_by' => null, 'reviewed_at' => null, 'created_by' => $context->actorId(),
                        'updated_by' => $context->actorId(), 'createtime' => $now, 'updatetime' => $now, 'deletetime' => null,
                    ], $context);
                } catch (PDOException $exception) {
                    if ($exception->getCode() === '23000') { $duplicates++; continue; }
                    throw $exception;
                }
                $created[] = $this->presentQuestion((array) $this->gateway->find('questions', $id, $context));
            }
            $this->gateway->update('geo_question_generation_batches', $batchId, [
                'generated_count' => count($created), 'duplicate_count' => $duplicates,
                'status' => 'succeeded', 'completed_at' => $this->date($now),
            ], $context);
            $this->audit->record('geo_questions_generated', $context, 'geo_question_generation_batch', (string) $batchId, '', [
                'generated_count' => count($created), 'duplicate_count' => $duplicates,
            ]);
            $this->pdo->commit();
            return ['batch_id' => $batchId, 'generated_count' => count($created), 'duplicate_count' => $duplicates, 'questions' => $created];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    public function review(int $questionId, bool $enabled, TenantContext $context): array
    {
        $row = $this->gateway->find('questions', $questionId, $context);
        if (!is_array($row) || $row['source'] !== 'ai' || $row['deletetime'] !== null) {
            throw new GeoException('Generated question was not found.', 404);
        }
        $targetStatus = $enabled ? 'normal' : 'disabled';
        if (
            $row['status'] === $targetStatus
            && $row['reviewed_by'] !== null
            && $row['reviewed_at'] !== null
        ) {
            return $this->presentQuestion($row);
        }
        if ($row['status'] !== 'pending_review') {
            throw new GeoException('Generated question review decision is final.', 409);
        }
        $this->gateway->update('questions', $questionId, [
            'status' => $targetStatus, 'reviewed_by' => $context->actorId(),
            'reviewed_at' => $this->date($this->now()), 'updated_by' => $context->actorId(), 'updatetime' => $this->now(),
        ], $context);
        $this->audit->record('geo_question_reviewed', $context, 'question', (string) $questionId, '', ['enabled' => $enabled]);
        return $this->presentQuestion((array) $this->gateway->find('questions', $questionId, $context));
    }

    private function evidence(array $ids, TenantContext $context): array
    {
        $profiles = $this->gateway->list('company_profiles', $context, [], 1);
        if ($profiles === []) { throw new GeoException('Company profile is required.', 409); }
        $raw = $profiles[0];
        $profile = [
            'brand' => (string) $raw['brand'], 'industry' => (string) $raw['industry'],
            'products' => json_decode((string) $raw['products_json'], true) ?: [],
            'website' => $raw['website'], 'introduction' => (string) $raw['introduction'],
        ];
        $knowledge = [];
        foreach ($ids as $id) {
            $row = $this->gateway->find('knowledge_items', $id, $context);
            if (!is_array($row) || $row['deletetime'] !== null || $row['status'] !== 'normal' || $row['type'] !== 'text') {
                throw new GeoException('Enabled text knowledge was not found.', 404);
            }
            $knowledge[] = ['id' => $id, 'title' => (string) $row['title'], 'content' => (string) $row['content'], 'sha256' => hash('sha256', (string) $row['content'])];
        }
        return [$profile, $knowledge];
    }

    private function questionExists(TenantContext $context, int $keywordId, string $digest): bool
    {
        return $this->gateway->count('questions', $context, [
            'keyword_id' => $keywordId, 'text_hash' => $digest, 'deletetime' => null,
        ]) > 0;
    }

    private function presentQuestion(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'keyword_id' => (int) $row['keyword_id'],
            'text' => (string) $row['text'], 'source' => (string) $row['source'],
            'status' => (string) $row['status'],
            'reviewed_by' => $row['reviewed_by'] === null ? null : (int) $row['reviewed_by'],
            'reviewed_at' => $row['reviewed_at'] ?? null,
        ];
    }

    private function positiveId($value, string $label): int
    {
        if (!(is_int($value) || (is_string($value) && ctype_digit($value))) || (int) $value < 1) { throw new GeoException($label . ' ID is invalid.'); }
        return (int) $value;
    }

    private function ids($value): array
    {
        if (!is_array($value) || $value === []) { throw new GeoException('At least one knowledge item is required.'); }
        return array_values(array_unique(array_map(fn($id): int => $this->positiveId($id, 'Knowledge'), $value)));
    }

    private function now(): int { return (int) call_user_func($this->clock); }
    private function date(int $timestamp): string { return date('Y-m-d H:i:s', $timestamp); }
    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}

<?php

declare(strict_types=1);

namespace app\common\geo;

use app\common\ai\AiGenerationRequest;
use app\common\ai\AiProvider;
use app\common\tenant\AuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use PDO;
use Throwable;

final class TargetAnswerService
{
    private PDO $pdo;
    private TenantDataGateway $gateway;
    private AuditLogger $audit;
    private $clock;

    public function __construct(PDO $pdo, TenantDataGateway $gateway, AuditLogger $audit, ?callable $clock = null)
    {
        $this->pdo = $pdo; $this->gateway = $gateway; $this->audit = $audit;
        $this->clock = $clock ?: static fn(): int => time();
    }

    public function generate(int $questionId, array $knowledgeIds, AiProvider $provider, TenantContext $context): array
    {
        $question = $this->enabledQuestion($questionId, $context);
        [$profile, $knowledge] = $this->snapshots($knowledgeIds, $context);
        $input = [
            'question' => ['id' => $questionId, 'text' => $question['text']],
            'sources' => $this->promptSources($profile, $knowledge),
            'response_requirements' => [
                'language' => 'zh-CN',
                'style' => 'natural_recommendation',
                'direct_answer_first' => true,
                'supported_information_points' => ['min' => 2, 'max' => 4, 'when_supported' => true],
                'insufficient_evidence' => 'state_limits_conservatively',
            ],
        ];
        $result = $provider->generate(new AiGenerationRequest(
            'Return only one JSON object with keys answer, recommendation_reason, and facts; do not use Markdown fences. '
            . 'Write answer in natural Chinese as a useful recommendation: directly answer the user first, then explain 2 to 4 distinct points that are directly relevant to the question and supported by the supplied sources. '
            . 'Use confirmed enterprise facts only. When fewer than 2 relevant points are supported, state the evidence limitation conservatively instead of inventing details. '
            . 'Do not add unsupported prices, addresses, availability, cases, awards, capabilities, or web-search claims. Avoid one-word answers, single-sentence restatements, and filler. '
            . 'facts must be a non-empty array. Every fact must contain claim, source_type, source_id, and evidence_quote. '
            . 'Copy source_type and source_id exactly from one supplied sources item, and copy evidence_quote verbatim from that item\'s evidence_text. '
            . 'Do not invent facts, source IDs, or quotes. Example shape: '
            . '{"answer":"...","recommendation_reason":"...","facts":[{"claim":"...","source_type":"profile","source_id":"introduction","evidence_quote":"exact source text"}]}.',
            $this->json($input),
            4096
        ));
        $output = json_decode($result->rawText(), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($output)) { throw new GeoException('AI target answer output is invalid.'); }
        [$answer, $reason, $facts] = $this->validatedOutput($output, $profile, $knowledge);
        return $this->insertVersion(
            $question, $answer, $reason, $facts, $profile, $knowledge,
            'ai', $result->providerCode(), $result->modelName(), $result->requestId(), $context
        );
    }

    public function revise(int $answerVersionId, array $input, TenantContext $context): array
    {
        $original = $this->raw($answerVersionId, $context);
        $profile = $this->decode((string) $original['profile_snapshot_json']);
        $knowledge = $this->decode((string) $original['knowledge_snapshot_json']);
        [$answer, $reason, $facts] = $this->validatedOutput($input, $profile, $knowledge);
        $question = $this->enabledQuestion((int) $original['question_id'], $context);
        return $this->insertVersion($question, $answer, $reason, $facts, $profile, $knowledge, 'manual', null, null, null, $context);
    }

    public function confirm(int $answerVersionId, TenantContext $context): array
    {
        $row = $this->raw($answerVersionId, $context);
        if (!in_array($row['status'], ['pending_review', 'confirmed'], true)) { throw new GeoException('Answer version cannot be confirmed.', 409); }
        $this->gateway->update('geo_answer_versions', $answerVersionId, [
            'status' => 'confirmed', 'reviewed_by' => $context->actorId(), 'reviewed_at' => $this->date($this->now()),
        ], $context);
        $this->audit->record('geo_answer_confirmed', $context, 'geo_answer_version', (string) $answerVersionId, '', []);
        return $this->detail($answerVersionId, $context);
    }

    public function detail(int $answerVersionId, TenantContext $context): array
    {
        $row = $this->raw($answerVersionId, $context);
        return [
            'id' => (int) $row['id'], 'question_id' => (int) $row['question_id'],
            'keyword_id' => (int) $row['keyword_id'], 'version_no' => (int) $row['version_no'],
            'answer' => (string) $row['answer_text'], 'recommendation_reason' => $row['recommendation_reason'],
            'facts' => $this->decode((string) $row['facts_json']), 'evidence' => $this->decode((string) $row['evidence_json']),
            'profile_snapshot' => $this->decode((string) $row['profile_snapshot_json']),
            'knowledge_snapshot' => $this->decode((string) $row['knowledge_snapshot_json']),
            'provider_code' => $row['provider_code'], 'model_name' => $row['model_name'],
            'source' => (string) $row['source'], 'status' => (string) $row['status'],
            'reviewed_by' => $row['reviewed_by'] === null ? null : (int) $row['reviewed_by'],
            'created_at' => (string) $row['created_at'], 'reviewed_at' => $row['reviewed_at'],
        ];
    }

    public function versions(int $questionId, int $page, int $perPage, TenantContext $context): array
    {
        if ($questionId < 1) { throw new GeoException('Question ID is invalid.'); }
        if ($page < 1 || $perPage < 1 || $perPage > 100) { throw new GeoException('Answer history pagination is invalid.'); }
        if (!is_array($this->gateway->find('questions', $questionId, $context))) {
            throw new GeoException('Question was not found.', 404);
        }
        $filters = ['question_id' => $questionId];
        $total = $this->gateway->count('geo_answer_versions', $context, $filters);
        $rows = $this->gateway->list('geo_answer_versions', $context, $filters, $perPage, ($page - 1) * $perPage);
        return [
            'items' => array_map(fn(array $row): array => $this->presentVersion($row), $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    private function insertVersion(
        array $question,
        string $answer,
        ?string $reason,
        array $facts,
        array $profile,
        array $knowledge,
        string $source,
        ?string $providerCode,
        ?string $modelName,
        ?string $requestId,
        TenantContext $context
    ): array {
        $now = $this->now();
        $this->pdo->beginTransaction();
        try {
            $lockedQuestion = $this->gateway->findMany('questions', [(int) $question['id']], $context, [], true);
            if (!is_array($lockedQuestion)) { throw new GeoException('Question was not found.', 404); }
            $filters = ['question_id' => (int) $question['id']];
            $versionCount = $this->gateway->count('geo_answer_versions', $context, $filters);
            $latest = $versionCount > 0
                ? $this->gateway->list('geo_answer_versions', $context, $filters, 1, $versionCount - 1)
                : [];
            $versionNo = $latest === [] ? 1 : (int) $latest[0]['version_no'] + 1;
            $id = $this->gateway->insert('geo_answer_versions', [
                'question_id' => (int) $question['id'], 'keyword_id' => (int) $question['keyword_id'], 'version_no' => $versionNo,
                'answer_text' => $answer, 'recommendation_reason' => $reason, 'facts_json' => $this->json($facts),
                'evidence_json' => $this->json(['facts' => $facts]), 'profile_snapshot_json' => $this->json($profile),
                'knowledge_snapshot_json' => $this->json($knowledge), 'job_id' => null, 'ai_call_id' => null,
                'provider_code' => $providerCode, 'model_name' => $modelName, 'provider_request_id' => $requestId,
                'source' => $source, 'status' => 'pending_review', 'created_by' => $context->actorId(),
                'reviewed_by' => null, 'created_at' => $this->date($now), 'reviewed_at' => null,
            ], $context);
            $this->audit->record('geo_answer_version_created', $context, 'geo_answer_version', (string) $id, '', ['version_no' => $versionNo, 'source' => $source]);
            $this->pdo->commit();
            return $this->detail($id, $context);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    private function enabledQuestion(int $id, TenantContext $context): array
    {
        $row = $this->gateway->find('questions', $id, $context);
        if (!is_array($row) || $row['deletetime'] !== null || $row['status'] !== 'normal') { throw new GeoException('Enabled question was not found.', 404); }
        return $row;
    }

    private function raw(int $id, TenantContext $context): array
    {
        $row = $this->gateway->find('geo_answer_versions', $id, $context);
        if (!is_array($row)) { throw new GeoException('GEO answer version was not found.', 404); }
        return $row;
    }

    private function presentVersion(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'question_id' => (int) $row['question_id'],
            'keyword_id' => (int) $row['keyword_id'], 'version_no' => (int) $row['version_no'],
            'answer' => (string) $row['answer_text'], 'recommendation_reason' => $row['recommendation_reason'],
            'facts' => $this->decode((string) $row['facts_json']), 'evidence' => $this->decode((string) $row['evidence_json']),
            'source' => (string) $row['source'], 'status' => (string) $row['status'],
            'provider_code' => $row['provider_code'], 'model_name' => $row['model_name'],
            'created_at' => (string) $row['created_at'], 'reviewed_at' => $row['reviewed_at'],
        ];
    }

    private function snapshots(array $knowledgeIds, TenantContext $context): array
    {
        $profiles = $this->gateway->list('company_profiles', $context, [], 1);
        if ($profiles === []) { throw new GeoException('Company profile is required.', 409); }
        $raw = $profiles[0];
        $profile = ['brand' => (string) $raw['brand'], 'industry' => (string) $raw['industry'], 'products' => json_decode((string) $raw['products_json'], true) ?: [], 'website' => $raw['website'], 'introduction' => (string) $raw['introduction']];
        $knowledge = [];
        foreach (array_values(array_unique(array_map('intval', $knowledgeIds))) as $id) {
            $row = $this->gateway->find('knowledge_items', $id, $context);
            if ($id < 1 || !is_array($row) || $row['deletetime'] !== null || $row['status'] !== 'normal' || $row['type'] !== 'text') { throw new GeoException('Enabled text knowledge was not found.', 404); }
            $knowledge[] = ['id' => $id, 'title' => (string) $row['title'], 'content' => (string) $row['content'], 'sha256' => hash('sha256', (string) $row['content'])];
        }
        return [$profile, $knowledge];
    }

    private function validatedOutput(array $output, array $profile, array $knowledge): array
    {
        $answer = trim((string) ($output['answer'] ?? ''));
        $reason = trim((string) ($output['recommendation_reason'] ?? ''));
        $facts = $output['facts'] ?? null;
        if ($answer === '' || !is_array($facts) || $facts === []) { throw new GeoException('Target answer and factual evidence are required.'); }
        $profileKeys = array_keys(array_filter($profile, static fn($value): bool => $value !== null && $value !== '' && $value !== []));
        $knowledgeIds = array_map(static fn(array $item): int => (int) $item['id'], $knowledge);
        $knowledgeById = [];
        foreach ($knowledge as $item) { $knowledgeById[(int) $item['id']] = (string) $item['content']; }
        $validated = [];
        foreach ($facts as $fact) {
            if (!is_array($fact)) { throw new GeoException('Target answer fact is invalid.'); }
            $claim = trim((string) ($fact['claim'] ?? ''));
            $type = (string) ($fact['source_type'] ?? '');
            $sourceId = $fact['source_id'] ?? null;
            $quote = trim((string) ($fact['evidence_quote'] ?? ''));
            $valid = $claim !== '' && (($type === 'profile' && is_string($sourceId) && in_array($sourceId, $profileKeys, true))
                || ($type === 'knowledge' && (is_int($sourceId) || ctype_digit((string) $sourceId)) && in_array((int) $sourceId, $knowledgeIds, true)));
            if (!$valid) { throw new GeoException('Target answer contains an unsupported fact reference.'); }
            $sourceText = $type === 'knowledge'
                ? ($knowledgeById[(int) $sourceId] ?? '')
                : $this->sourceText($profile[(string) $sourceId] ?? null);
            if ($quote === '' || !$this->containsQuote($sourceText, $quote)) {
                throw new GeoException('Target answer evidence quote is not present in its referenced source.');
            }
            $validated[] = [
                'claim' => $claim, 'source_type' => $type,
                'source_id' => $type === 'knowledge' ? (int) $sourceId : $sourceId,
                'evidence_quote' => $quote,
            ];
        }
        return [$answer, $reason === '' ? null : $reason, $validated];
    }

    private function promptSources(array $profile, array $knowledge): array
    {
        $sources = [];
        foreach ($profile as $sourceId => $value) {
            $evidenceText = $this->sourceText($value);
            if ($evidenceText === '') { continue; }
            $sources[] = [
                'source_type' => 'profile',
                'source_id' => (string) $sourceId,
                'source_label' => $this->profileSourceLabel((string) $sourceId),
                'evidence_text' => $evidenceText,
            ];
        }
        foreach ($knowledge as $item) {
            $sources[] = [
                'source_type' => 'knowledge',
                'source_id' => (int) $item['id'],
                'source_label' => (string) $item['title'],
                'evidence_text' => (string) $item['content'],
            ];
        }
        return $sources;
    }

    private function profileSourceLabel(string $sourceId): string
    {
        $labels = [
            'brand' => '企业品牌',
            'industry' => '所属行业',
            'products' => '已确认产品与服务',
            'website' => '企业官网',
            'introduction' => '企业简介',
        ];
        return $labels[$sourceId] ?? $sourceId;
    }

    private function decode(string $json): array
    {
        $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) { throw new GeoException('Stored GEO evidence is invalid.', 500); }
        return $value;
    }
    private function sourceText($value): string
    {
        if (is_array($value)) { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
        return (string) $value;
    }
    private function containsQuote(string $source, string $quote): bool
    {
        $source = preg_replace('/\s+/u', ' ', trim($source)) ?: '';
        $quote = preg_replace('/\s+/u', ' ', trim($quote)) ?: '';
        if ($source === '' || $quote === '') { return false; }
        return function_exists('mb_stripos') ? mb_stripos($source, $quote, 0, 'UTF-8') !== false : stripos($source, $quote) !== false;
    }
    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private function now(): int { return (int) call_user_func($this->clock); }
    private function date(int $timestamp): string { return date('Y-m-d H:i:s', $timestamp); }
}

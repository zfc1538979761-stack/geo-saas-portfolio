<?php

declare(strict_types=1);

namespace app\common\geo;

use app\common\ai\AiGenerationRequest;
use app\common\ai\AiProvider;
use app\common\tenant\AuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;

final class OptimizationSuggestionService
{
    private TenantDataGateway $gateway;
    private AuditLogger $audit;
    private $clock;
    public function __construct(TenantDataGateway $gateway, AuditLogger $audit, ?callable $clock = null) { $this->gateway = $gateway; $this->audit = $audit; $this->clock = $clock ?: static fn(): int => time(); }

    public function generate(int $resultId, AiProvider $provider, TenantContext $context): array
    {
        $result = $this->gateway->find('geo_visibility_results', $resultId, $context);
        if (!is_array($result)) { throw new GeoException('Visibility result was not found.', 404); }
        $existing = $this->gateway->list('geo_optimization_suggestions', $context, ['result_id' => $resultId], 1);
        if ($existing !== []) { return $this->present($existing[0]); }
        if ($result['visibility_status'] === 'recommended') { throw new GeoException('Recommended result does not require an optimization suggestion.', 409); }
        $questionText = trim((string) $result['question_snapshot']);
        if ($questionText === '') { throw new GeoException('Visibility question snapshot is missing.', 409); }
        $knowledge = [];
        foreach ($this->gateway->list('knowledge_items', $context, ['status' => 'normal', 'deletetime' => null], 100) as $item) {
            if ($item['type'] === 'text') { $knowledge[] = ['id' => (int) $item['id'], 'title' => (string) $item['title'], 'sha256' => hash('sha256', (string) $item['content'])]; }
        }
        $basis = ['question' => $questionText, 'brand' => (string) $result['brand_snapshot'], 'visibility_status' => (string) $result['visibility_status'], 'raw_answer' => (string) $result['raw_answer'], 'citations' => json_decode((string) $result['citations_json'], true, 64, JSON_THROW_ON_ERROR), 'available_knowledge' => $knowledge];
        $response = $provider->generate(new AiGenerationRequest(
            'Return strict JSON arrays: knowledge_gaps, question_directions, answer_directions, content_directions. Do not invent enterprise facts and do not propose automatic paid publishing.',
            json_encode($basis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            2048
        ));
        $output = json_decode($response->rawText(), true, 32, JSON_THROW_ON_ERROR);
        foreach (['knowledge_gaps', 'question_directions', 'answer_directions', 'content_directions'] as $key) {
            if (!is_array($output[$key] ?? null) || $output[$key] === []) { throw new GeoException('Optimization suggestion output is invalid.'); }
            $output[$key] = array_values(array_map(static fn($value): string => trim((string) $value), $output[$key]));
        }
        $comparisons = $this->gateway->list('geo_visibility_comparisons', $context, ['current_result_id' => $resultId], 1);
        $id = $this->gateway->insert('geo_optimization_suggestions', [
            'result_id' => $resultId, 'comparison_id' => $comparisons === [] ? null : (int) $comparisons[0]['id'],
            'job_id' => null, 'ai_call_id' => null, 'provider_code' => $response->providerCode(), 'model_name' => $response->modelName(),
            'provider_request_id' => $response->requestId(), 'missed_question' => $questionText,
            'knowledge_gaps_json' => $this->json($output['knowledge_gaps']), 'question_directions_json' => $this->json($output['question_directions']),
            'answer_directions_json' => $this->json($output['answer_directions']), 'content_directions_json' => $this->json($output['content_directions']),
            'generation_basis_json' => $this->json($basis), 'status' => 'pending_review', 'created_by' => $context->actorId(),
            'reviewed_by' => null, 'created_at' => date('Y-m-d H:i:s', (int) call_user_func($this->clock)), 'reviewed_at' => null,
        ], $context);
        $this->audit->record('geo_optimization_suggestion_created', $context, 'geo_optimization_suggestion', (string) $id, '', ['result_id' => $resultId]);
        return $this->present((array) $this->gateway->find('geo_optimization_suggestions', $id, $context));
    }
    private function present(array $row): array { return ['id' => (int) $row['id'], 'result_id' => (int) $row['result_id'], 'status' => (string) $row['status'], 'missed_question' => (string) $row['missed_question'], 'knowledge_gaps' => json_decode((string) $row['knowledge_gaps_json'], true, 32, JSON_THROW_ON_ERROR), 'question_directions' => json_decode((string) $row['question_directions_json'], true, 32, JSON_THROW_ON_ERROR), 'answer_directions' => json_decode((string) $row['answer_directions_json'], true, 32, JSON_THROW_ON_ERROR), 'content_directions' => json_decode((string) $row['content_directions_json'], true, 32, JSON_THROW_ON_ERROR)]; }
    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}

<?php

declare(strict_types=1);

namespace app\common\geo;

use app\common\tenant\AuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;

final class VisibilityTrendService
{
    private TenantDataGateway $gateway;
    private AuditLogger $audit;
    private $clock;
    public function __construct(TenantDataGateway $gateway, AuditLogger $audit, ?callable $clock = null) { $this->gateway = $gateway; $this->audit = $audit; $this->clock = $clock ?: static fn(): int => time(); }

    public function compare(int $currentResultId, TenantContext $context): array
    {
        $current = $this->gateway->find('geo_visibility_results', $currentResultId, $context);
        if (!is_array($current)) { throw new GeoException('Current visibility result was not found.', 404); }
        $existing = $this->gateway->list('geo_visibility_comparisons', $context, ['current_result_id' => $currentResultId], 1);
        if ($existing !== []) { return $this->present($existing[0]); }
        $currentCheck = $this->gateway->find('geo_visibility_checks', (int) $current['check_id'], $context);
        if (!is_array($currentCheck) || $currentCheck['check_type'] === 'baseline') {
            throw new GeoException('A Baseline is the start of a trend and cannot compare to itself.', 409);
        }
        $previous = $currentCheck['plan_id'] === null
            ? $this->latestDimensionResultBefore($current, $currentResultId, $context)
            : $this->latestPlanResultOrBaseline($current, $currentCheck, $currentResultId, (int) $currentCheck['plan_id'], $context);
        if ($previous === null) { throw new GeoException('No comparable visibility baseline exists.', 409); }
        $rank = ['not_visible' => 0, 'mentioned' => 1, 'recommended' => 2];
        $delta = ($rank[$current['visibility_status']] ?? -1) <=> ($rank[$previous['visibility_status']] ?? -1);
        $trend = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'unchanged');
        $previousUrls = $this->urls((string) $previous['citations_json']); $currentUrls = $this->urls((string) $current['citations_json']);
        $id = $this->gateway->insert('geo_visibility_comparisons', [
            'previous_result_id' => (int) $previous['id'], 'current_result_id' => $currentResultId, 'trend' => $trend,
            'answer_change_summary' => hash_equals(hash('sha256', (string) $previous['raw_answer']), hash('sha256', (string) $current['raw_answer'])) ? 'unchanged' : 'changed',
            'citation_change_json' => json_encode(['added' => array_values(array_diff($currentUrls, $previousUrls)), 'removed' => array_values(array_diff($previousUrls, $currentUrls))], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => date('Y-m-d H:i:s', (int) call_user_func($this->clock)),
        ], $context);
        $this->audit->record('geo_visibility_compared', $context, 'geo_visibility_comparison', (string) $id, '', ['trend' => $trend]);
        return $this->present((array) $this->gateway->find('geo_visibility_comparisons', $id, $context));
    }
    private function latestPlanResultOrBaseline(array $current, array $currentCheck, int $currentResultId, int $planId, TenantContext $context): ?array
    {
        $plan = $this->gateway->find('geo_visibility_plans', $planId, $context);
        if (!is_array($plan) || (int) ($plan['baseline_result_id'] ?? 0) < 1) {
            throw new GeoException('Recurring visibility plan has no Baseline.', 409);
        }
        $baseline = $this->gateway->find('geo_visibility_results', (int) $plan['baseline_result_id'], $context);
        $baselineCheck = is_array($baseline)
            ? $this->gateway->find('geo_visibility_checks', (int) $baseline['check_id'], $context)
            : null;
        if (!is_array($baseline) || !$this->sameDimension($baseline, $current)
            || (string) $baseline['classifier_version'] !== (string) $current['classifier_version']
            || !is_array($baselineCheck) || $baselineCheck['check_type'] !== 'baseline'
            || (string) $baselineCheck['provider_code'] !== (string) $plan['provider_code']
            || (string) $currentCheck['provider_code'] !== (string) $plan['provider_code']
            || !$this->isBefore($baseline, $current, $currentResultId)) {
            throw new GeoException('Recurring visibility plan Baseline is incompatible.', 409);
        }
        $previous = $baseline;
        foreach ($this->gateway->list('geo_visibility_checks', $context, ['plan_id' => $planId, 'status' => 'succeeded'], 1000) as $check) {
            $results = $this->gateway->list('geo_visibility_results', $context, ['check_id' => (int) $check['id']], 1);
            if ($results === []) { continue; }
            $row = $results[0];
            if ((int) $row['id'] === $currentResultId || !$this->sameDimension($row, $current)
                || (string) $row['classifier_version'] !== (string) $current['classifier_version']) { continue; }
            if ($this->isBefore($row, $current, $currentResultId) && $this->isAfter($row, $previous)) { $previous = $row; }
        }
        return $previous;
    }
    private function latestDimensionResultBefore(array $current, int $currentResultId, TenantContext $context): ?array
    {
        $history = $this->gateway->list('geo_visibility_results', $context, [
            'question_id' => (int) $current['question_id'], 'platform' => $current['platform'], 'model_name' => $current['model_name'],
        ], 1000);
        $previous = null;
        foreach ($history as $row) {
            if ($this->isBefore($row, $current, $currentResultId) && ($previous === null || $this->isAfter($row, $previous))) { $previous = $row; }
        }
        return $previous;
    }
    private function sameDimension(array $left, array $right): bool
    {
        return (int) $left['question_id'] === (int) $right['question_id']
            && (string) $left['platform'] === (string) $right['platform']
            && (string) $left['model_name'] === (string) $right['model_name'];
    }
    private function isBefore(array $candidate, array $current, int $currentResultId): bool
    {
        return (string) $candidate['detected_at'] < (string) $current['detected_at']
            || ((string) $candidate['detected_at'] === (string) $current['detected_at'] && (int) $candidate['id'] < $currentResultId);
    }
    private function isAfter(array $candidate, array $previous): bool
    {
        return (string) $candidate['detected_at'] > (string) $previous['detected_at']
            || ((string) $candidate['detected_at'] === (string) $previous['detected_at'] && (int) $candidate['id'] > (int) $previous['id']);
    }
    private function urls(string $json): array { $items = json_decode($json, true, 64, JSON_THROW_ON_ERROR); $urls = []; foreach ((array) $items as $item) { if (is_array($item) && isset($item['url'])) { $urls[] = (string) $item['url']; } } return array_values(array_unique($urls)); }
    private function present(array $row): array { return ['id' => (int) $row['id'], 'previous_result_id' => (int) $row['previous_result_id'], 'current_result_id' => (int) $row['current_result_id'], 'trend' => (string) $row['trend'], 'answer_change_summary' => $row['answer_change_summary'], 'citation_change' => json_decode((string) $row['citation_change_json'], true, 64, JSON_THROW_ON_ERROR)]; }
}

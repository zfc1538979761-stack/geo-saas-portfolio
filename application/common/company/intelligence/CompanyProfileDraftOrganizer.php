<?php

declare(strict_types=1);

namespace app\common\company\intelligence;

use app\common\ai\AiGenerationRequest;
use app\common\ai\AiProvider;
use JsonException;

final class CompanyProfileDraftOrganizer
{
    private const FIELDS = [
        'company_name', 'brand', 'region', 'website', 'industry', 'main_business',
        'products_services', 'introduction', 'advantages', 'qualifications', 'cases',
    ];

    public function organize(array $candidate, array $sources, AiProvider $provider, bool $allowUnsourced = false): array
    {
        $sourceById = [];
        foreach ($sources as $source) {
            $id = (int) ($source['id'] ?? 0);
            if ($id < 1) {
                throw new CompanyDiscoveryException('invalid_evidence', '候选企业来源缺少可追溯 ID。', 422);
            }
            $sourceById[$id] = $source;
        }
        if ($sourceById === [] && !$allowUnsourced) {
            throw new CompanyDiscoveryException('invalid_evidence', '候选企业没有可验证来源。', 422);
        }
        $evidence = [
            'candidate' => [
                'name' => (string) ($candidate['name'] ?? ''),
                'region' => (string) ($candidate['region'] ?? ''),
                'website' => $candidate['website'] ?? null,
                'unified_social_credit_code' => $candidate['unified_social_credit_code'] ?? null,
                'public_summary' => (string) ($candidate['public_summary'] ?? ''),
            ],
            'sources' => array_values($sources),
        ];
        $prompt = $allowUnsourced
            ? '根据用户提供的企业名称、地区和 AI 候选摘要整理企业画像草稿。'
                . '当前没有公开来源，所有事实都处于待审核状态。'
                . '输出严格 JSON：facts 每项必须包含 field_key、value、source_ids，且 source_ids 必须为 []；'
                . 'field_key 必须使用以下英文键之一：company_name、brand、region、website、industry、'
                . 'main_business、products_services、introduction、advantages、qualifications、cases；'
                . '不要使用中文 field_key；至少包含 company_name 和 region；'
                . '禁止编造来源 ID、URL 或声称已联网核验；不要输出 inferences。'
            : 'Organize a company profile from only the supplied public evidence. Return strict JSON with facts [{field_key,value,source_ids}] and inferences [{field_key,value,reason}]. Never invent a source ID or present an inference as a fact.';
        $result = $provider->generate(new AiGenerationRequest(
            $prompt,
            json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            2048
        ));
        try {
            $decoded = json_decode($result->rawText(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CompanyDiscoveryException('invalid_ai_output', 'AI 企业画像草稿格式无效。', 422);
        }
        if (!is_array($decoded) || !is_array($decoded['facts'] ?? null)) {
            throw new CompanyDiscoveryException('invalid_ai_output', 'AI 企业画像草稿缺少事实列表。', 422);
        }

        $draft = [];
        $facts = [];
        foreach ($decoded['facts'] as $fact) {
            if (!is_array($fact)) {
                throw new CompanyDiscoveryException('invalid_ai_output', 'AI 企业画像事实格式无效。', 422);
            }
            $field = $this->normalizeFieldKey(trim((string) ($fact['field_key'] ?? '')));
            $sourceIds = array_values(array_unique(array_map('intval', (array) ($fact['source_ids'] ?? []))));
            if (!in_array($field, self::FIELDS, true) || array_key_exists($field, $draft)) {
                throw new CompanyDiscoveryException('invalid_ai_output', 'AI 企业画像事实字段或来源无效。', 422);
            }
            if ($allowUnsourced) {
                if ($sourceIds !== []) {
                    throw new CompanyDiscoveryException('invalid_ai_output', 'AI 候选草稿不得引用来源。', 422);
                }
            } else {
                if ($sourceIds === []) {
                    throw new CompanyDiscoveryException('invalid_ai_output', 'AI 企业画像事实缺少来源。', 422);
                }
                foreach ($sourceIds as $sourceId) {
                    if (!isset($sourceById[$sourceId])) {
                        throw new CompanyDiscoveryException('invalid_ai_output', 'AI 企业画像引用了未知来源。', 422);
                    }
                }
            }
            $value = $this->value($field, $fact['value'] ?? null);
            $draft[$field] = $value;
            $facts[] = [
                'field_key' => $field,
                'value' => $value,
                'fact_type' => $allowUnsourced ? 'ai_inference' : 'source_fact',
                'status' => 'discovered',
                'source_ids' => $sourceIds,
            ];
        }
        if (!isset($draft['company_name']) || !isset($draft['region'])) {
            throw new CompanyDiscoveryException('invalid_ai_output', 'AI 企业画像草稿缺少企业名称或地区事实。', 422);
        }

        $inferences = [];
        foreach ((array) ($decoded['inferences'] ?? []) as $inference) {
            if (!is_array($inference)) {
                throw new CompanyDiscoveryException('invalid_ai_output', 'AI 推断格式无效。', 422);
            }
            $field = trim((string) ($inference['field_key'] ?? ''));
            if (!in_array($field, self::FIELDS, true)) {
                throw new CompanyDiscoveryException('invalid_ai_output', 'AI 推断字段无效。', 422);
            }
            $inferences[] = [
                'field_key' => $field,
                'value' => $this->value($field, $inference['value'] ?? null),
                'reason' => trim((string) ($inference['reason'] ?? '')),
                'fact_type' => 'ai_inference',
                'status' => 'discovered',
                'source_ids' => [],
            ];
        }

        return [
            'draft' => $draft,
            'facts' => $facts,
            'inferences' => $inferences,
            'provider_code' => $result->providerCode(),
            'model_name' => $result->modelName(),
            'provider_request_id' => $result->requestId(),
        ];
    }

    private function value(string $field, $value)
    {
        if (in_array($field, ['products_services', 'advantages', 'qualifications', 'cases'], true)) {
            $items = array_values(array_filter(array_map(static function ($item): string {
                return trim((string) $item);
            }, (array) $value), static function (string $item): bool { return $item !== ''; }));
            if ($items === []) {
                throw new CompanyDiscoveryException('invalid_ai_output', 'AI 企业画像列表字段不能为空。', 422);
            }
            return $items;
        }
        $text = trim((string) $value);
        if ($text === '') {
            throw new CompanyDiscoveryException('invalid_ai_output', 'AI 企业画像文本字段不能为空。', 422);
        }
        return $text;
    }

    private function normalizeFieldKey(string $field): string
    {
        $labels = [
            '企业名称' => 'company_name',
            '品牌' => 'brand',
            '所属地区' => 'region',
            '地区' => 'region',
            '官网' => 'website',
            '行业' => 'industry',
            '主营业务' => 'main_business',
            '核心业务' => 'main_business',
            '产品/服务' => 'products_services',
            '企业简介' => 'introduction',
            '业务方向' => 'introduction',
            '配套服务' => 'advantages',
            '公开优势' => 'advantages',
            '公开资质' => 'qualifications',
            '公开案例' => 'cases',
        ];
        return $labels[$field] ?? $field;
    }
}

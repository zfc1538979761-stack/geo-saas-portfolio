<?php

declare(strict_types=1);

namespace app\common\company\intelligence;

use app\common\ai\AiGenerationRequest;
use app\common\ai\AiProvider;
use JsonException;

final class AiFallbackCompanyDiscoveryProvider implements CompanyDiscoveryProvider
{
    private AiProvider $aiProvider;
    private string $externalCode;

    public function __construct(AiProvider $aiProvider, string $externalCode = 'ToolNotOpen')
    {
        $this->aiProvider = $aiProvider;
        $this->externalCode = $externalCode;
    }

    public function providerCode(): string
    {
        return 'doubao_chat_fallback';
    }

    public function discover(CompanyDiscoveryRequest $request): CompanyDiscoveryResult
    {
        if ($this->aiProvider->providerCode() === 'mock') {
            throw new CompanyDiscoveryException(
                'configuration_error',
                'AI 降级 Provider 当前为 Mock，不能作为真实候选画像来源。',
                500,
                $this->providerCode(),
                $this->externalCode,
                false,
                false
            );
        }

        $prompt = '根据以下企业名称和地区生成候选企业画像。'
            . '只允许输出一个 JSON 对象，字段为 name、region、website、unified_social_credit_code、public_summary。'
            . 'website 和 unified_social_credit_code 在没有真实资料时必须是 null，禁止编造 URL 或信用代码。'
            . 'public_summary 必须以“[AI候选 / pending_review / 未联网核验]”开头，随后是 2-4 句简洁候选画像。'
            . '禁止声称已联网发现、禁止添加任何搜索来源或链接。只输出 JSON。';
        $input = json_encode($request->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $result = $this->aiProvider->generate(new AiGenerationRequest($prompt, $input, 2048));

        try {
            $decoded = json_decode($result->rawText(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CompanyDiscoveryException(
                'invalid_ai_output',
                'AI 候选企业画像格式无效。',
                422,
                $this->providerCode(),
                $this->externalCode,
                false,
                false
            );
        }
        if (!is_array($decoded)) {
            throw new CompanyDiscoveryException(
                'invalid_ai_output',
                'AI 候选企业画像格式无效。',
                422,
                $this->providerCode(),
                $this->externalCode,
                false,
                false
            );
        }

        $name = trim((string) ($decoded['name'] ?? $request->companyName()));
        $region = trim((string) ($decoded['region'] ?? $request->region()));
        $summary = trim((string) ($decoded['public_summary'] ?? ''));
        if ($name === '' || $region === '' || $summary === '') {
            throw new CompanyDiscoveryException(
                'invalid_ai_output',
                'AI 候选企业画像缺少必要字段。',
                422,
                $this->providerCode(),
                $this->externalCode,
                false,
                false
            );
        }
        if (strpos($summary, '[AI候选 / pending_review / 未联网核验]') !== 0) {
            $summary = '[AI候选 / pending_review / 未联网核验] ' . $summary;
        }

        $website = $this->optional($decoded['website'] ?? null);
        if ($website !== null && filter_var($website, FILTER_VALIDATE_URL) === false) {
            $website = null;
        }

        $externalKey = 'ai-' . substr(
            hash('sha256', $request->companyName() . '|' . $request->region() . '|' . $result->requestId()),
            0,
            32
        );

        return new CompanyDiscoveryResult(
            [[
                'external_key' => $externalKey,
                'name' => $name,
                'region' => $region,
                'website' => $website,
                'unified_social_credit_code' => $this->optional($decoded['unified_social_credit_code'] ?? null),
                'public_summary' => $summary,
                'sources' => [],
            ]],
            $this->providerCode(),
            $result->requestId(),
            false,
            true
        );
    }

    private function optional($value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}

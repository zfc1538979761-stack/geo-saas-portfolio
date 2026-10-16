<?php

declare(strict_types=1);

namespace app\common\company\intelligence;

final class CompanyDiscoveryResult
{
    private array $candidates;
    private string $providerCode;
    private string $requestId;
    private bool $networkExecuted;
    private bool $allowUnsourcedCandidate;

    public function __construct(
        array $candidates,
        string $providerCode,
        string $requestId,
        bool $networkExecuted,
        bool $allowUnsourcedCandidate = false
    ) {
        $providerCode = trim($providerCode);
        $requestId = trim($requestId);
        if ($providerCode === '' || strlen($providerCode) > 64 || $requestId === '' || strlen($requestId) > 191) {
            throw new CompanyDiscoveryException('invalid_provider_response', '企业发现 Provider 元数据无效。', 502);
        }
        $this->allowUnsourcedCandidate = $allowUnsourcedCandidate;
        if (count($candidates) > 100) {
            throw new CompanyDiscoveryException('invalid_provider_response', '企业候选数量超过允许上限。', 502);
        }
        $normalized = [];
        $candidateKeys = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                throw new CompanyDiscoveryException('invalid_provider_response', '企业候选格式无效。', 502);
            }
            $name = trim((string) ($candidate['name'] ?? ''));
            $region = trim((string) ($candidate['region'] ?? ''));
            $externalKey = trim((string) ($candidate['external_key'] ?? ''));
            $sources = (array) ($candidate['sources'] ?? []);
            if ($name === '' || $region === '' || $externalKey === ''
                || (!$this->allowUnsourcedCandidate && $sources === [])
                || !self::fits($name, 191) || !self::fits($region, 191) || !self::fits($externalKey, 191)
                || isset($candidateKeys[$externalKey]) || count($sources) > 100) {
                throw new CompanyDiscoveryException('invalid_provider_response', '企业候选缺少名称、地区或来源证据。', 502);
            }
            $candidateKeys[$externalKey] = true;
            $normalizedSources = [];
            $sourceKeys = [];
            foreach ($sources as $source) {
                if (!is_array($source)) {
                    throw new CompanyDiscoveryException('invalid_provider_response', '企业候选来源格式无效。', 502);
                }
                $url = trim((string) ($source['source_url'] ?? ''));
                $title = trim((string) ($source['source_title'] ?? ''));
                $sourceKey = trim((string) ($source['source_key'] ?? ''));
                $excerpt = trim((string) ($source['public_excerpt'] ?? ''));
                $publishedAt = self::optional($source['published_at'] ?? null);
                if ($sourceKey === '' || $title === '' || !self::isHttpUrl($url)
                    || !self::fits($sourceKey, 191) || !self::fits($url, 2048)
                    || !self::fits($title, 512) || !self::fits($excerpt, 10000)
                    || ($publishedAt !== null && !self::fits($publishedAt, 64))
                    || isset($sourceKeys[$sourceKey])) {
                    throw new CompanyDiscoveryException('invalid_provider_response', '企业候选来源证据不完整。', 502);
                }
                $sourceKeys[$sourceKey] = true;
                $normalizedSources[] = [
                    'source_key' => $sourceKey,
                    'source_url' => $url,
                    'source_title' => $title,
                    'public_excerpt' => $excerpt,
                    'published_at' => $publishedAt,
                ];
            }
            $website = self::optional($candidate['website'] ?? null);
            $creditCode = self::optional($candidate['unified_social_credit_code'] ?? null);
            $summary = trim((string) ($candidate['public_summary'] ?? ''));
            if (($website !== null && (!self::isHttpUrl($website) || !self::fits($website, 2048)))
                || ($creditCode !== null && preg_match('/^[0-9A-HJ-NPQRTUWXY]{18}$/D', strtoupper($creditCode)) !== 1)
                || !self::fits($summary, 10000)) {
                throw new CompanyDiscoveryException('invalid_provider_response', '企业候选官网地址无效。', 502);
            }
            $normalized[] = [
                'external_key' => $externalKey,
                'name' => $name,
                'region' => $region,
                'website' => $website,
                'unified_social_credit_code' => $creditCode === null ? null : strtoupper($creditCode),
                'public_summary' => $summary,
                'sources' => $normalizedSources,
            ];
        }
        $this->candidates = $normalized;
        $this->providerCode = $providerCode;
        $this->requestId = $requestId;
        $this->networkExecuted = $networkExecuted;
    }

    public function candidates(): array { return $this->candidates; }
    public function providerCode(): string { return $this->providerCode; }
    public function requestId(): string { return $this->requestId; }
    public function networkExecuted(): bool { return $this->networkExecuted; }

    private static function optional($value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private static function isHttpUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private static function fits(string $value, int $maximum): bool
    {
        return mb_check_encoding($value, 'UTF-8') && mb_strlen($value, 'UTF-8') <= $maximum;
    }
}

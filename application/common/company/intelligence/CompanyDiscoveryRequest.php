<?php

declare(strict_types=1);

namespace app\common\company\intelligence;

final class CompanyDiscoveryRequest
{
    private string $companyName;
    private string $region;
    private ?string $website;
    private ?string $unifiedSocialCreditCode;

    public function __construct(
        string $companyName,
        string $region,
        ?string $website = null,
        ?string $unifiedSocialCreditCode = null
    ) {
        $companyName = trim($companyName);
        $region = trim($region);
        $website = self::optional($website);
        $unifiedSocialCreditCode = self::optional($unifiedSocialCreditCode);
        if ($companyName === '' || mb_strlen($companyName) > 191) {
            throw new CompanyDiscoveryException('invalid_request', '企业名称不能为空且不能超过191个字符。', 422);
        }
        if ($region === '' || mb_strlen($region) > 191) {
            throw new CompanyDiscoveryException('invalid_request', '地区不能为空且不能超过191个字符。', 422);
        }
        if ($website !== null && (strlen($website) > 2048 || !self::isHttpUrl($website))) {
            throw new CompanyDiscoveryException('invalid_request', '官网地址格式不正确。', 422);
        }
        if ($unifiedSocialCreditCode !== null
            && preg_match('/^[0-9A-HJ-NPQRTUWXY]{18}$/D', strtoupper($unifiedSocialCreditCode)) !== 1) {
            throw new CompanyDiscoveryException('invalid_request', '统一社会信用代码格式不正确。', 422);
        }
        $this->companyName = $companyName;
        $this->region = $region;
        $this->website = $website;
        $this->unifiedSocialCreditCode = $unifiedSocialCreditCode === null
            ? null
            : strtoupper($unifiedSocialCreditCode);
    }

    public function companyName(): string { return $this->companyName; }
    public function region(): string { return $this->region; }
    public function website(): ?string { return $this->website; }
    public function unifiedSocialCreditCode(): ?string { return $this->unifiedSocialCreditCode; }

    public function toArray(): array
    {
        return [
            'company_name' => $this->companyName,
            'region' => $this->region,
            'website' => $this->website,
            'unified_social_credit_code' => $this->unifiedSocialCreditCode,
        ];
    }

    private static function optional(?string $value): ?string
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
}

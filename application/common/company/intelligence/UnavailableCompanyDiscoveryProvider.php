<?php

declare(strict_types=1);

namespace app\common\company\intelligence;

final class UnavailableCompanyDiscoveryProvider implements CompanyDiscoveryProvider
{
    private string $providerCode;
    private string $externalCode;

    public function __construct(string $providerCode = 'responses_web_search', string $externalCode = 'ToolNotOpen')
    {
        $this->providerCode = $providerCode;
        $this->externalCode = $externalCode;
    }

    public function providerCode(): string { return $this->providerCode; }

    public function discover(CompanyDiscoveryRequest $request): CompanyDiscoveryResult
    {
        throw new CompanyDiscoveryException(
            'external_capability_unavailable',
            '企业公开信息发现能力暂未开通，请稍后重试或先手动完善企业资料。',
            503,
            $this->providerCode,
            $this->externalCode,
            false,
            false
        );
    }
}

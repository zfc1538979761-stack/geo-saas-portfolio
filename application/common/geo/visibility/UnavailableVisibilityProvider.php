<?php

declare(strict_types=1);

namespace app\common\geo\visibility;

final class UnavailableVisibilityProvider implements VisibilityProvider
{
    private string $code;
    private string $externalCode;

    public function __construct(string $code, string $externalCode = 'ToolNotOpen')
    {
        $this->code = trim($code);
        $this->externalCode = trim($externalCode);
    }

    public function providerCode(): string { return $this->code; }

    public function query(VisibilityRequest $request): VisibilityResponse
    {
        throw new VisibilityProviderException(
            'External visibility capability is not enabled.',
            'external_capability_unavailable',
            $this->code,
            $this->externalCode,
            false
        );
    }
}

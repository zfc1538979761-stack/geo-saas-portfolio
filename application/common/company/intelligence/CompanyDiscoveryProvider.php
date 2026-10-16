<?php

declare(strict_types=1);

namespace app\common\company\intelligence;

interface CompanyDiscoveryProvider
{
    public function providerCode(): string;

    public function discover(CompanyDiscoveryRequest $request): CompanyDiscoveryResult;
}

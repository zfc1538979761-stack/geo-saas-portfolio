<?php

declare(strict_types=1);

namespace app\common\geo\visibility;

interface VisibilityProvider
{
    public function providerCode(): string;

    public function query(VisibilityRequest $request): VisibilityResponse;
}

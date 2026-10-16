<?php

declare(strict_types=1);

namespace app\common\ai;

interface AiProvider
{
    public function providerCode(): string;

    public function generate(AiGenerationRequest $request): AiGenerationResult;
}

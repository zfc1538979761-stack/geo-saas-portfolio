<?php

declare(strict_types=1);

namespace app\common\ai;

use InvalidArgumentException;

final class AiGenerationRequest
{
    private string $systemPrompt;
    private string $userPrompt;
    private int $maxOutputTokens;

    public function __construct(string $systemPrompt, string $userPrompt, int $maxOutputTokens)
    {
        $systemPrompt = trim($systemPrompt);
        $userPrompt = trim($userPrompt);
        if ($systemPrompt === '' || $userPrompt === '' || $maxOutputTokens < 1 || $maxOutputTokens > 8192) {
            throw new InvalidArgumentException('AI generation request is invalid.');
        }
        $this->systemPrompt = $systemPrompt;
        $this->userPrompt = $userPrompt;
        $this->maxOutputTokens = $maxOutputTokens;
    }

    public function systemPrompt(): string { return $this->systemPrompt; }
    public function userPrompt(): string { return $this->userPrompt; }
    public function maxOutputTokens(): int { return $this->maxOutputTokens; }
}

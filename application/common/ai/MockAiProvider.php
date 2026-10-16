<?php

declare(strict_types=1);

namespace app\common\ai;

final class MockAiProvider implements AiProvider
{
    private string $title;
    private string $content;
    private ?string $raw;

    public function __construct(string $title = 'Mock generated title', string $content = 'Mock generated article body.', ?string $raw = null)
    {
        $this->title = $title;
        $this->content = $content;
        $raw = $raw === null ? null : trim($raw);
        $this->raw = $raw === '' ? null : $raw;
    }

    public function providerCode(): string { return 'mock'; }

    public function generate(AiGenerationRequest $request): AiGenerationResult
    {
        $raw = $this->raw ?? json_encode(
                ['title' => $this->title, 'content' => $this->content],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        return new AiGenerationResult(
            $raw,
            $this->providerCode(),
            'mock-t07',
            'mock-' . substr(hash('sha256', $request->systemPrompt() . "\n" . $request->userPrompt()), 0, 24),
            null,
            null,
            0
        );
    }
}

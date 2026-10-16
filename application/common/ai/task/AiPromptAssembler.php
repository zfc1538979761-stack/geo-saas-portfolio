<?php

declare(strict_types=1);

namespace app\common\ai\task;

use app\common\ai\AiGenerationRequest;

final class AiPromptAssembler
{
    public function assemble(array $snapshot, int $maxOutputTokens): AiGenerationRequest
    {
        $prompt = (array) ($snapshot['prompt'] ?? []);
        $system = trim((string) ($prompt['content'] ?? ''))
            . "\n\nReturn only a JSON object with exactly two string keys in this order: title, content."
            . ' Do not use Markdown code fences or add any other keys or text.';
        $context = [
            'company' => (array) ($snapshot['company'] ?? []),
            'keyword' => (array) ($snapshot['keyword'] ?? []),
            'question' => (array) ($snapshot['question'] ?? []),
            'text_knowledge' => (array) ($snapshot['knowledge'] ?? []),
            'geo_target_answer' => (array) ($snapshot['geo_target_answer'] ?? []),
        ];
        return new AiGenerationRequest(
            $system,
            json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $maxOutputTokens
        );
    }
}

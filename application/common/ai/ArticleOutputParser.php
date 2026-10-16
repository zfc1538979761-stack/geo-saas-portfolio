<?php

declare(strict_types=1);

namespace app\common\ai;

use app\common\ai\exception\AiProviderException;

final class ArticleOutputParser
{
    private int $titleMax;
    private int $contentMax;

    public function __construct(int $titleMax, int $contentMax)
    {
        $this->titleMax = $titleMax;
        $this->contentMax = $contentMax;
    }

    public function parse(string $raw): array
    {
        try {
            $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new AiProviderException('AI article response is not valid JSON.', 'invalid_response', false);
        }
        if (!is_array($value) || array_keys($value) !== ['title', 'content']) {
            throw new AiProviderException('AI article response structure is invalid.', 'invalid_response', false);
        }
        $title = is_string($value['title']) ? trim($value['title']) : '';
        $content = is_string($value['content']) ? trim($value['content']) : '';
        if (
            $title === '' || $content === ''
            || mb_strlen($title, 'UTF-8') > $this->titleMax
            || mb_strlen($content, 'UTF-8') > $this->contentMax
            || preg_match('/[\x00-\x1F\x7F]/u', $title) === 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $content) === 1
        ) {
            throw new AiProviderException('AI article response content is invalid.', 'invalid_response', false);
        }
        return ['title' => $title, 'content' => $content];
    }
}

<?php

declare(strict_types=1);

namespace app\common\content;

use app\common\foundation\ApiErrorCode;

final class ContentTextNormalizer
{
    public function keyword($value): string
    {
        return $this->text($value, ContentRules::KEYWORD_MAX_LENGTH, '关键词');
    }

    public function question($value): string
    {
        return $this->text($value, ContentRules::QUESTION_MAX_LENGTH, '问题');
    }

    public function search($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return $this->text($value, ContentRules::SEARCH_MAX_LENGTH, '搜索内容');
    }

    public function status($value): string
    {
        $status = is_string($value) ? trim($value) : '';
        if (!in_array($status, ContentRules::statuses(), true)) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, '状态值无效', 422);
        }
        return $status;
    }

    public function optionalStatus($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->status($value);
    }

    public function questionDigest(string $normalizedQuestion): string
    {
        $normalizedQuestion = $this->question($normalizedQuestion);
        return hash('sha256', mb_strtolower($normalizedQuestion, 'UTF-8'), true);
    }

    private function text($value, int $maximumLength, string $label): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, $label . '格式无效', 422);
        }
        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) === 1) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, $label . '包含不可见或异常空白字符', 422);
        }
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if ($character !== ' ' && preg_match('/^\p{Zs}$/u', $character) === 1) {
                throw new ContentException(ApiErrorCode::INVALID_REQUEST, $label . '包含异常空白字符', 422);
            }
        }
        $normalized = trim($value, ' ');
        if ($normalized === '') {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, $label . '不能为空', 422);
        }
        if (mb_strlen($normalized, 'UTF-8') > $maximumLength) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, $label . '长度超出限制', 422);
        }
        return $normalized;
    }
}

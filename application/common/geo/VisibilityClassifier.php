<?php

declare(strict_types=1);

namespace app\common\geo;

use app\common\geo\visibility\VisibilityResponse;
use InvalidArgumentException;

final class VisibilityClassifier
{
    public function classify(string $brand, VisibilityResponse $response): array
    {
        $brand = trim($brand);
        if ($brand === '') {
            throw new InvalidArgumentException('Enterprise brand is required for visibility classification.');
        }
        $mentioned = mb_stripos($response->rawAnswer(), $brand, 0, 'UTF-8') !== false;
        $evidence = $response->evidence();
        $recommended = $mentioned && ($evidence['explicit_recommendation'] ?? false) === true;
        $reason = $recommended ? trim((string) ($evidence['recommendation_reason'] ?? '')) : '';
        return [
            'status' => $recommended ? 'recommended' : ($mentioned ? 'mentioned' : 'not_visible'),
            'brand_mentioned' => $mentioned,
            'explicitly_recommended' => $recommended,
            'recommendation_reason' => $reason === '' ? null : $reason,
        ];
    }
}

<?php

declare(strict_types=1);

$json = static function (string $name): array {
    $raw = getenv($name);
    if ($raw === false || trim($raw) === '') { return []; }
    $value = json_decode($raw, true);
    return is_array($value) ? $value : [];
};

return [
    'driver' => getenv('GEO_VISIBILITY_DRIVER') ?: 'mock',
    'environment' => getenv('GEO_APP_ENV') ?: 'development',
    'web_search_enabled' => filter_var(getenv('GEO_VISIBILITY_WEB_SEARCH_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN),
    'external_code' => getenv('GEO_VISIBILITY_EXTERNAL_CODE') ?: 'ToolNotOpen',
    'queue' => getenv('GEO_VISIBILITY_QUEUE') ?: 'geo_visibility',
    'max_attempts' => max(1, min(10, (int) (getenv('GEO_VISIBILITY_MAX_ATTEMPTS') ?: 3))),
    'retry_delays' => [5, 30, 120],
    'mock' => [
        'raw_answer' => getenv('GEO_VISIBILITY_MOCK_RAW_ANSWER') ?: 'Mock visibility answer.',
        'citations' => $json('GEO_VISIBILITY_MOCK_CITATIONS'),
        'evidence' => $json('GEO_VISIBILITY_MOCK_EVIDENCE'),
    ],
];

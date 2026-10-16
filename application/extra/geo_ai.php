<?php

use think\Env;

$process = static function (string $name, $fallback = null) {
    $value = getenv('GEO_' . strtoupper($name));
    return $value === false ? $fallback : $value;
};

return [
    'portfolio' => true,
    'provider' => $process('ai_provider', Env::get('ai.provider', 'mock')),
    'environment' => $process('app_env', Env::get('app.env', 'production')),
    'queue' => $process('ai_queue', Env::get('ai.queue', 'geo_ai_generation')),
    'max_attempts' => (int) $process('ai_max_attempts', Env::get('ai.max_attempts', 3)),
    'retry_delays' => [5, 30, 120],
    'max_output_tokens' => (int) $process('ai_max_output_tokens', Env::get('ai.max_output_tokens', 2048)),
    'ark' => [
        'base_url' => $process('ark_base_url', Env::get('ark.base_url', '')),
        'api_key' => $process('ark_api_key', Env::get('ark.api_key', '')),
        'endpoint_id' => $process('ark_endpoint_id', Env::get('ark.endpoint_id', '')),
        'model_name' => $process('ark_model_name', Env::get('ark.model_name', '')),
        'connect_timeout' => (float) $process('ark_connect_timeout', Env::get('ark.connect_timeout', 5)),
        'request_timeout' => (float) $process('ark_request_timeout', Env::get('ark.request_timeout', 30)),
        'thinking_type' => $process('ark_thinking_type', Env::get('ark.thinking_type', 'disabled')),
        'temperature' => (float) $process('ark_temperature', Env::get('ark.temperature', 0)),
    ],
    'mock' => [
        'title' => 'Mock generated title',
        'content' => 'Mock generated article body.',
        'raw' => $process('ai_mock_raw', Env::get('ai.mock_raw', '')),
    ],
    'pricing' => [
        'input_per_million' => $process('ai_input_cost_per_million', Env::get('ai.input_cost_per_million', '')),
        'output_per_million' => $process('ai_output_cost_per_million', Env::get('ai.output_cost_per_million', '')),
        'currency' => $process('ai_cost_currency', Env::get('ai.cost_currency', 'CNY')),
        'version' => $process('ai_pricing_version', Env::get('ai.pricing_version', '')),
    ],
];

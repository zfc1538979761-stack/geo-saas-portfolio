<?php

use think\Env;

$process = static function (string $name, $fallback = null) {
    $value = getenv('GEO_' . strtoupper($name));
    return $value === false ? $fallback : $value;
};

return [
    'driver' => $process('company_discovery_driver', Env::get('company_discovery.driver', 'responses_web_search')),
    'web_search_enabled' => filter_var(
        $process('company_discovery_web_search_enabled', Env::get('company_discovery.web_search_enabled', false)),
        FILTER_VALIDATE_BOOLEAN
    ),
    'external_code' => $process(
        'company_discovery_external_code',
        Env::get('company_discovery.external_code', 'ToolNotOpen')
    ),
];

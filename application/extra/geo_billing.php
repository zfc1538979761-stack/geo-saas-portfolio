<?php

use think\Env;

$process = static function (string $name, $fallback = null) {
    $value = getenv('GEO_' . strtoupper($name));
    return $value === false ? $fallback : $value;
};

return [
    'currency' => 'CNY',
    'manual_adjust_enabled' => filter_var(
        $process('billing_manual_adjust_enabled', Env::get('billing.manual_adjust_enabled', 'false')),
        FILTER_VALIDATE_BOOLEAN
    ),
    'manual_adjust_permission' => 'wallet.adjust',
];

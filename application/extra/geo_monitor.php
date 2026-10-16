<?php

use think\Env;

$int = static function (string $name, int $fallback): int {
    $value = getenv($name);
    if ($value === false) {
        $value = Env::get(strtolower(str_replace('_', '.', substr($name, 4))), $fallback);
    }
    return max(0, (int) $value);
};

$bool = static function (string $name, bool $fallback): bool {
    $value = getenv($name);
    if ($value === false) {
        $value = Env::get(strtolower(str_replace('_', '.', substr($name, 4))), $fallback);
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $fallback;
};

return [
    // This is an operator-controlled cadence, not a confirmed business SLA.
    'period_seconds' => $int('GEO_MONITOR_PERIOD_SECONDS', 300),
    'thresholds' => [
        'queue_backlog' => $int('GEO_MONITOR_QUEUE_BACKLOG', 20),
        'queue_failed' => $int('GEO_MONITOR_QUEUE_FAILED', 1),
        'ai_recent_failures' => $int('GEO_MONITOR_AI_FAILURES', 3),
        'ai_rate_limited' => $int('GEO_MONITOR_AI_RATE_LIMITED', 1),
        'supplier_recent_failures' => $int('GEO_MONITOR_SUPPLIER_FAILURES', 3),
        'supplier_timeouts' => $int('GEO_MONITOR_SUPPLIER_TIMEOUTS', 1),
        'order_stale_hours' => $int('GEO_MONITOR_ORDER_STALE_HOURS', 24),
        'disk_free_min_bytes' => $int('GEO_MONITOR_DISK_FREE_MIN_BYTES', 536870912),
        'high_risk_window_seconds' => $int('GEO_MONITOR_HIGH_RISK_WINDOW_SECONDS', 60),
        'high_risk_max_actions' => $int('GEO_MONITOR_HIGH_RISK_MAX_ACTIONS', 20),
    ],
    'probe' => [
        'database' => $bool('GEO_MONITOR_PROBE_DATABASE', true),
        'redis' => $bool('GEO_MONITOR_PROBE_REDIS', true),
        'storage' => $bool('GEO_MONITOR_PROBE_STORAGE', false),
        'disk' => $bool('GEO_MONITOR_PROBE_DISK', true),
    ],
];

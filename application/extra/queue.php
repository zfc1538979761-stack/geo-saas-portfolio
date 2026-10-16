<?php

use think\Env;

$processValue = static function (string $name, $fallback) {
    $value = getenv('GEO_REDIS_' . strtoupper($name));
    return $value !== false ? $value : $fallback;
};

return [
    'connector'  => Env::get('queue.connector', 'Redis'),
    'expire'     => (int) Env::get('queue.retry_after', 90),
    'default'    => Env::get('queue.default', 'default'),
    'host'       => $processValue('hostname', Env::get('redis.hostname', '127.0.0.1')),
    'port'       => (int) $processValue('hostport', Env::get('redis.hostport', 6379)),
    'password'   => $processValue('password', Env::get('redis.password', '')),
    'select'     => (int) $processValue('select', Env::get('redis.select', 0)),
    'timeout'    => (int) $processValue('timeout', Env::get('redis.timeout', 2)),
    'persistent' => (bool) $processValue('persistent', Env::get('redis.persistent', false)),
];

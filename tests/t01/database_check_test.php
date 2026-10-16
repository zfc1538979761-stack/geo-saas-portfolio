<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sensitiveUsername = 't01-sensitive-username';
$sensitivePassword = 'test-only-password-placeholder';
$sensitiveDatabase = 't01_sensitive_database';
$overrides = [
    'GEO_APP_ENV' => 'testing',
    'GEO_APP_TOKEN_KEY' => 'test-only-database-check-token-key',
    'GEO_DATABASE_TYPE' => 'mysql',
    'GEO_DATABASE_HOSTNAME' => '127.0.0.1',
    'GEO_DATABASE_HOSTPORT' => '1',
    'GEO_DATABASE_DATABASE' => $sensitiveDatabase,
    'GEO_DATABASE_USERNAME' => $sensitiveUsername,
    'GEO_DATABASE_PASSWORD' => $sensitivePassword,
    'GEO_DATABASE_PREFIX' => 'fa_',
];
$previous = [];

foreach ($overrides as $key => $value) {
    $existing = getenv($key);
    $previous[$key] = $existing === false ? null : $existing;
    putenv($key . '=' . $value);
}

$output = [];
$exitCode = 0;
exec(
    escapeshellarg(PHP_BINARY)
    . ' '
    . escapeshellarg($root . DIRECTORY_SEPARATOR . 'think')
    . ' geo:db:check 2>&1',
    $output,
    $exitCode
);

foreach ($previous as $key => $value) {
    if ($value === null) {
        putenv($key);
    } else {
        putenv($key . '=' . $value);
    }
}

$text = implode("\n", $output);
if ($exitCode === 0) {
    fwrite(STDERR, "FAIL: database check must fail for an unreachable database\n");
    exit(1);
}
if (strpos($text, 'FAIL: database connection unavailable') === false) {
    fwrite(STDERR, "FAIL: database check must return the safe failure contract\n");
    exit(1);
}
foreach ([$sensitiveUsername, $sensitivePassword, $sensitiveDatabase, $root] as $forbidden) {
    if (strpos($text, $forbidden) !== false) {
        fwrite(STDERR, "FAIL: database check leaked sensitive configuration or a local path\n");
        exit(1);
    }
}

fwrite(STDOUT, "PASS: database check fails safely without leaking configuration\n");

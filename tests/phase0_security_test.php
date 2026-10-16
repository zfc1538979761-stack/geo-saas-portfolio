<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function requireContains(string $haystack, string $needle, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fail($message);
    }
}

$appConfig = file_get_contents($root . '/application/config.php');
$databaseConfig = file_get_contents($root . '/application/database.php');
$envSample = parse_ini_file($root . '/.env.sample', true, INI_SCANNER_RAW);

if ($appConfig === false || $databaseConfig === false || $envSample === false) {
    fail('required configuration files must be readable');
}

requireContains(
    $appConfig,
    "Env::get('app.token_key', '')",
    'token HMAC key must be loaded from app.token_key'
);
requireContains(
    $appConfig,
    "throw new RuntimeException('Missing required app.token_key configuration.')",
    'missing token HMAC key must fail explicitly'
);
requireContains(
    $appConfig,
    "'key'      => \$tokenKey",
    'token configuration must use the environment-backed key'
);

if (preg_match("/'token'\\s*=>\\s*\\[(.*?)\\]/s", $appConfig, $tokenBlock) !== 1) {
    fail('token configuration block must exist');
}
if (preg_match("/'key'\\s*=>\\s*'[^']+'/s", $tokenBlock[1]) === 1) {
    fail('token configuration must not contain a hard-coded HMAC key');
}

requireContains(
    $databaseConfig,
    "Env::get('database.username', '')",
    'database username must not have a built-in account default'
);

$sampleTokenKey = $envSample['app']['token_key'] ?? '';
$sampleUsername = $envSample['database']['username'] ?? '';
$samplePassword = $envSample['database']['password'] ?? '';

if ($sampleTokenKey !== 'change-me-with-a-random-64-character-hex-value') {
    fail('app.token_key sample must use the approved placeholder');
}
if ($sampleUsername !== 'change-me') {
    fail('database.username sample must use the approved placeholder');
}
if ($samplePassword !== 'change-me') {
    fail('database.password sample must use the approved placeholder');
}

$documentationFiles = [
    'README.md',
    'HANDOVER.md',
    'LOCAL-SETUP.md',
    'POC-FASTADMIN.md',
    'PROJECT_STATUS.md',
];
$forbiddenDocumentationPatterns = [
    '/用户名\\s*[：:]/u',
    '/数据库名称\\/用户名/u',
    '/管理员用户名/u',
    '/C:\\\\Users\\\\[^\\\\\\s`]+/u',
];

foreach ($documentationFiles as $relativePath) {
    $content = file_get_contents($root . '/' . $relativePath);
    if ($content === false) {
        fail("documentation file {$relativePath} must be readable");
    }
    foreach ($forbiddenDocumentationPatterns as $pattern) {
        if (preg_match($pattern, $content) === 1) {
            fail("documentation file {$relativePath} contains a prohibited account identifier format");
        }
    }
}

fwrite(STDOUT, "PASS: phase 0 security configuration\n");

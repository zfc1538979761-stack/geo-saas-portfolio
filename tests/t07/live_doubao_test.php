<?php

declare(strict_types=1);

if (getenv('GEO_T07_LIVE_TEST_ENABLE') !== '1') { fwrite(STDOUT, "SKIP: T07 live Doubao test is not enabled\n"); exit(0); }
$path = (string) getenv('GEO_T07_LIVE_ENV_FILE');
if ($path === '' || !is_file($path)) { fwrite(STDERR, "FAIL: T07 live credential file is unavailable\n"); exit(1); }
$config = parse_ini_file($path, true, INI_SCANNER_RAW); $ark = is_array($config) ? (array) ($config['ark'] ?? []) : [];
$mapping = ['base_url' => 'GEO_ARK_BASE_URL', 'api_key' => 'GEO_ARK_API_KEY', 'endpoint_id' => 'GEO_ARK_ENDPOINT_ID', 'model_name' => 'GEO_ARK_MODEL_NAME'];
foreach ($mapping as $field => $environment) {
    $value = trim((string) ($ark[$field] ?? ''));
    if ($value === '') { fwrite(STDERR, "FAIL: configured Ark field is missing\n"); exit(1); }
    putenv($environment . '=' . $value);
}
putenv('GEO_T07_HTTP_PROVIDER=doubao_ark');
require __DIR__ . DIRECTORY_SEPARATOR . 'http_worker_test.php';

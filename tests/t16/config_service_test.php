<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\internal\InternalConfigService;

function t16ConfigAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$path = tempnam(sys_get_temp_dir(), 'geo-t16-env-');
if ($path === false) {
    throw new RuntimeException('Temporary environment file could not be created.');
}
$sample = <<<'TXT'
[app]
env = development

[ark]
api_key = old-ark-key

[media_market]
username = old-user
password = old-pass
access_code = old-code
TXT;
file_put_contents($path, $sample);

$service = new InternalConfigService($path);
$aiStatus = $service->status('ai');
t16ConfigAssert($aiStatus['scope'] === 'ai', 'AI config scope is normalized.');
t16ConfigAssert($aiStatus['fields']['api_key']['configured'] === true, 'Configured API key is detected.');
t16ConfigAssert($aiStatus['fields']['api_key']['masked'] === 'configured', 'API key is masked in status output.');

$supplierStatus = $service->status('supplier');
foreach (['username', 'password', 'access_code'] as $field) {
    t16ConfigAssert($supplierStatus['fields'][$field]['configured'] === true, 'Supplier credential is detected.');
    t16ConfigAssert($supplierStatus['fields'][$field]['masked'] === 'configured', 'Supplier credential is masked.');
}

$service->replaceKey('ai', 'api_key', 'new-ark-key-2026');
$updated = file_get_contents($path);
t16ConfigAssert(strpos($updated, 'new-ark-key-2026') !== false, 'New API key is written to environment file.');
t16ConfigAssert(strpos($updated, 'old-ark-key') === false, 'Old API key is replaced.');

$service->replaceKey('supplier', 'password', 'new-supplier-pass');
$updated2 = file_get_contents($path);
t16ConfigAssert(strpos($updated2, 'new-supplier-pass') !== false, 'Supplier password replacement is written.');
t16ConfigAssert(strpos($updated2, 'old-pass') === false, 'Old supplier password is replaced.');

$thrown = false;
try {
    $service->replaceKey('supplier', 'base_url', 'http://example.invalid');
} catch (\InvalidArgumentException $e) {
    $thrown = true;
}
t16ConfigAssert($thrown, 'Unsupported credential field is rejected.');

$statusAfter = $service->status('ai');
t16ConfigAssert($statusAfter['fields']['api_key']['masked'] === 'configured', 'Status remains masked after replacement.');

@unlink($path);
echo "PASS: T16 config service tests passed\n";

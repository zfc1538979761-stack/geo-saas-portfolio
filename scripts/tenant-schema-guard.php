<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\tenant\TenantResourceCatalog;
use app\common\tenant\TenantSchemaGuard;

$failures = [];
$registered = TenantResourceCatalog::registeredResourceNames();
$paths = glob(ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '*.php') ?: [];
foreach ($paths as $path) {
    $source = file_get_contents($path);
    if (!is_string($source)) {
        $failures[] = basename($path) . ': unreadable migration';
        continue;
    }
    foreach (TenantSchemaGuard::inspectMigration(basename($path), $source, $registered) as $error) {
        $failures[] = basename($path) . ': ' . $error;
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "PASS: tenant schema catalog guard\n");

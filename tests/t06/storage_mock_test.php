<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\content\foundation\ContentAssetPolicy;
use app\common\content\foundation\EnterpriseFileValidator;
use app\common\content\storage\MockMediaMarketStorageAdapter;
use app\common\content\storage\StorageAdapterFactory;
use app\common\content\storage\StorageException;
use app\common\tenant\TenantContext;

$passed = 0;
$failed = 0;
function t06StorageAssert(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        fwrite(STDOUT, "PASS: {$message}\n");
    } else {
        $failed++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}
function t06StorageReject(callable $operation, string $message): void
{
    try {
        $operation();
        t06StorageAssert(false, $message);
    } catch (Throwable $expected) {
        t06StorageAssert(true, $message);
    }
}

$required = [MockMediaMarketStorageAdapter::class, StorageAdapterFactory::class, StorageException::class];
foreach ($required as $class) {
    t06StorageAssert(class_exists($class), 'storage contract class exists: ' . $class);
}

if (count(array_filter($required, 'class_exists')) === count($required)) {
    $config = require ROOT_PATH . 'application' . DIRECTORY_SEPARATOR . 'extra'
        . DIRECTORY_SEPARATOR . 'geo_content.php';
    $policy = new ContentAssetPolicy((array) ($config['files'] ?? []));
    $validator = new EnterpriseFileValidator($policy);
    $source = tempnam(sys_get_temp_dir(), 'geo-t06-source-');
    if ($source === false) {
        throw new RuntimeException('temporary source unavailable');
    }
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    file_put_contents($source, $png);
    $upload = $validator->validate($source, 'brand.png', 'image/png', filesize($source));
    $contextA = TenantContext::enterprise(10, 101, 't06_storage_test');
    $contextB = TenantContext::enterprise(20, 202, 't06_storage_test');
    $adapter = new MockMediaMarketStorageAdapter('testing');
    try {
        t06StorageAssert($adapter instanceof MockMediaMarketStorageAdapter, 'testing may explicitly create MediaMarket OSS Mock');
        $object = $adapter->upload($upload, $contextA);
        t06StorageAssert(strpos($object->objectKey(), 'tenants/101/') === 0, 'Mock object key uses tenant namespace');
        t06StorageAssert(strpos($object->objectKey(), 'brand.png') === false, 'object key does not contain user filename');
        t06StorageAssert($object->provider() === 'media_market', 'Mock models MediaMarket provider only');
        $read = $adapter->read($object, $contextA);
        t06StorageAssert($read->contents() === $png && $read->mime() === 'image/png', 'owner can read exact Mock bytes');
        t06StorageReject(static function () use ($adapter, $object, $contextB): void {
            $adapter->read($object, $contextB);
        }, 'other tenant cannot read Mock object');
        t06StorageReject(static function () use ($adapter, $object, $contextB): void {
            $adapter->delete($object, $contextB);
        }, 'other tenant cannot delete Mock object');
        $adapter->delete($object, $contextA);
        t06StorageReject(static function () use ($adapter, $object, $contextA): void {
            $adapter->read($object, $contextA);
        }, 'deleted Mock object is unavailable');
        t06StorageAssert(strpos($adapter->temporaryRoot(), ROOT_PATH) !== 0, 'Mock storage is outside the business repository');
    } finally {
        $root = $adapter instanceof MockMediaMarketStorageAdapter ? $adapter->temporaryRoot() : '';
        unset($adapter);
        gc_collect_cycles();
        if ($root !== '') {
            t06StorageAssert(!is_dir($root), 'Mock temporary directory is automatically cleaned');
        }
        if (is_file($source)) {
            unlink($source);
        }
    }

    t06StorageReject(static function (): void {
        StorageAdapterFactory::create('production', ['driver' => 'mock']);
    }, 'production rejects Mock storage');
    t06StorageAssert(
        StorageAdapterFactory::create('testing', ['driver' => 'mock']) instanceof MockMediaMarketStorageAdapter,
        'factory exposes Mock only in an allowed environment'
    );
    $unavailable = StorageAdapterFactory::create('production', ['driver' => 'media_market']);
    t06StorageReject(static function () use ($unavailable, $upload, $contextA): void {
        $unavailable->upload($upload, $contextA);
    }, 'missing real MediaMarket contract fails closed without local fallback');
}

fwrite(STDOUT, "RESULT: {$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);

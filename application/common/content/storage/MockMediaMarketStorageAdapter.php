<?php

declare(strict_types=1);

namespace app\common\content\storage;

use app\common\content\foundation\ValidatedUpload;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantNamespace;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class MockMediaMarketStorageAdapter implements StorageAdapter
{
    private $root;
    private $cleanupOnDestruct;

    public function __construct(string $environment, ?string $root = null, bool $cleanupOnDestruct = true)
    {
        if (!in_array($environment, ['development', 'testing'], true)) {
            throw new StorageException('MediaMarket OSS Mock is forbidden in this environment.');
        }
        $this->root = $root ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'geo-saas-mediamarket-mock-' . bin2hex(random_bytes(12));
        $this->cleanupOnDestruct = $cleanupOnDestruct;
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new StorageException('Mock temporary directory is unavailable.');
        }
    }

    public function upload(ValidatedUpload $upload, TenantContext $context): StoredObject
    {
        $companyId = $this->companyId($context);
        $remoteId = bin2hex(random_bytes(20));
        $objectKey = TenantNamespace::fileKey($context, [bin2hex(random_bytes(16)) . '.' . $upload->extension()]);
        $target = $this->path($remoteId);
        if (!copy($upload->path(), $target)) {
            throw new StorageException('MediaMarket OSS Mock upload failed.');
        }
        return new StoredObject(
            'media_market',
            $remoteId,
            $objectKey,
            'mock-media-market:' . $remoteId,
            $companyId,
            $upload->detectedMime(),
            $upload->size(),
            $upload->sha256()
        );
    }

    public function read(StoredObject $object, TenantContext $context): StorageReadResult
    {
        $this->authorize($object, $context);
        $contents = @file_get_contents($this->path($object->remoteFileId()));
        if (!is_string($contents)) {
            throw new StorageException('Stored object is unavailable.');
        }
        return new StorageReadResult($contents, $object->mime());
    }

    public function delete(StoredObject $object, TenantContext $context): void
    {
        $this->authorize($object, $context);
        $path = $this->path($object->remoteFileId());
        if (!is_file($path) || !unlink($path)) {
            throw new StorageException('Stored object is unavailable.');
        }
    }

    public function temporaryRoot(): string
    {
        return $this->root;
    }

    public function __destruct()
    {
        if ($this->cleanupOnDestruct) {
            $this->cleanup();
        }
    }

    private function authorize(StoredObject $object, TenantContext $context): void
    {
        $companyId = $this->companyId($context);
        if (
            $object->provider() !== 'media_market'
            || $object->companyId() !== $companyId
            || strpos($object->objectKey(), 'tenants/' . $companyId . '/') !== 0
        ) {
            throw new StorageException('Stored object is unavailable.');
        }
    }

    private function companyId(TenantContext $context): int
    {
        $companyId = $context->companyId();
        if ($companyId === null || $companyId < 1 || $context->canBypass()) {
            throw new StorageException('Concrete tenant context is required.');
        }
        return $companyId;
    }

    private function path(string $remoteId): string
    {
        if (preg_match('/^[a-f0-9]{40}$/D', $remoteId) !== 1) {
            throw new StorageException('Stored object is unavailable.');
        }
        return $this->root . DIRECTORY_SEPARATOR . $remoteId . '.bin';
    }

    private function cleanup(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($this->root);
    }
}

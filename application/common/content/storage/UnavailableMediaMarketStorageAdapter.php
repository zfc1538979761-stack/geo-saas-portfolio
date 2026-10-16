<?php

declare(strict_types=1);

namespace app\common\content\storage;

use app\common\content\foundation\ValidatedUpload;
use app\common\tenant\TenantContext;

final class UnavailableMediaMarketStorageAdapter implements StorageAdapter
{
    public function upload(ValidatedUpload $upload, TenantContext $context): StoredObject
    {
        throw new StorageException('MediaMarket OSS official API contract is unavailable.');
    }

    public function read(StoredObject $object, TenantContext $context): StorageReadResult
    {
        throw new StorageException('MediaMarket OSS official API contract is unavailable.');
    }

    public function delete(StoredObject $object, TenantContext $context): void
    {
        throw new StorageException('MediaMarket OSS official API contract is unavailable.');
    }
}

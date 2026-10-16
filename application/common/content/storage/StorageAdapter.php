<?php

declare(strict_types=1);

namespace app\common\content\storage;

use app\common\content\foundation\ValidatedUpload;
use app\common\tenant\TenantContext;

interface StorageAdapter
{
    public function upload(ValidatedUpload $upload, TenantContext $context): StoredObject;
    public function read(StoredObject $object, TenantContext $context): StorageReadResult;
    public function delete(StoredObject $object, TenantContext $context): void;
}

<?php
declare(strict_types=1);
namespace app\common\portfolio;
use app\common\content\storage\StorageAdapter;
use app\common\content\storage\MockMediaMarketStorageAdapter;
use app\common\content\storage\StoredObject;
use app\common\content\storage\StorageReadResult;
use app\common\content\foundation\ValidatedUpload;
use app\common\tenant\TenantContext;
final class PortfolioStorageAdapter implements StorageAdapter {
    private MockMediaMarketStorageAdapter $adapter;
    public function __construct(string $environment,string $root) { $this->adapter=new MockMediaMarketStorageAdapter($environment,$root,false); }
    public function upload(ValidatedUpload $upload,TenantContext $context): StoredObject { return $this->adapter->upload($upload,$context); }
    public function read(StoredObject $object,TenantContext $context): StorageReadResult { return $this->adapter->read($object,$context); }
    public function delete(StoredObject $object,TenantContext $context): void { $this->adapter->delete($object,$context); }
}

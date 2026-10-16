<?php

declare(strict_types=1);

namespace app\common\content\storage;

final class StoredObject
{
    private $provider;
    private $remoteFileId;
    private $objectKey;
    private $locationIndex;
    private $companyId;
    private $mime;
    private $size;
    private $sha256;

    public function __construct(
        string $provider,
        string $remoteFileId,
        string $objectKey,
        string $locationIndex,
        int $companyId,
        string $mime,
        int $size,
        string $sha256
    ) {
        if ($companyId < 1) {
            throw new StorageException('Stored object tenant is invalid.');
        }
        $this->provider = $provider;
        $this->remoteFileId = $remoteFileId;
        $this->objectKey = $objectKey;
        $this->locationIndex = $locationIndex;
        $this->companyId = $companyId;
        $this->mime = $mime;
        $this->size = $size;
        $this->sha256 = $sha256;
    }

    public function provider(): string { return $this->provider; }
    public function remoteFileId(): string { return $this->remoteFileId; }
    public function objectKey(): string { return $this->objectKey; }
    public function locationIndex(): string { return $this->locationIndex; }
    public function companyId(): int { return $this->companyId; }
    public function mime(): string { return $this->mime; }
    public function size(): int { return $this->size; }
    public function sha256(): string { return $this->sha256; }
}

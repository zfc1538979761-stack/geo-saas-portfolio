<?php

declare(strict_types=1);

namespace app\common\content\storage;

final class StorageReadResult
{
    private $contents;
    private $mime;

    public function __construct(string $contents, string $mime)
    {
        $this->contents = $contents;
        $this->mime = $mime;
    }

    public function contents(): string { return $this->contents; }
    public function mime(): string { return $this->mime; }
}

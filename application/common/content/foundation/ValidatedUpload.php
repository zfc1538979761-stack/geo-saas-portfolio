<?php

declare(strict_types=1);

namespace app\common\content\foundation;

final class ValidatedUpload
{
    private $path;
    private $type;
    private $originalName;
    private $safeName;
    private $extension;
    private $declaredMime;
    private $detectedMime;
    private $size;
    private $sha256;

    public function __construct(
        string $path,
        string $type,
        string $originalName,
        string $safeName,
        string $extension,
        string $declaredMime,
        string $detectedMime,
        int $size,
        string $sha256
    ) {
        $this->path = $path;
        $this->type = $type;
        $this->originalName = $originalName;
        $this->safeName = $safeName;
        $this->extension = $extension;
        $this->declaredMime = $declaredMime;
        $this->detectedMime = $detectedMime;
        $this->size = $size;
        $this->sha256 = $sha256;
    }

    public function path(): string { return $this->path; }
    public function type(): string { return $this->type; }
    public function originalName(): string { return $this->originalName; }
    public function safeName(): string { return $this->safeName; }
    public function extension(): string { return $this->extension; }
    public function declaredMime(): string { return $this->declaredMime; }
    public function detectedMime(): string { return $this->detectedMime; }
    public function size(): int { return $this->size; }
    public function sha256(): string { return $this->sha256; }
}

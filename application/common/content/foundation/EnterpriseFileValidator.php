<?php

declare(strict_types=1);

namespace app\common\content\foundation;

use InvalidArgumentException;
use ZipArchive;

final class EnterpriseFileValidator
{
    private $policy;

    public function __construct(ContentAssetPolicy $policy)
    {
        $this->policy = $policy;
    }

    public function validate(string $path, string $originalName, string $declaredMime, int $declaredSize): ValidatedUpload
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('Uploaded file is unavailable.');
        }
        $originalName = $this->filename($originalName);
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (!$this->policy->hasExtension($extension)) {
            throw new InvalidArgumentException('File extension is not allowed.');
        }
        $expectedMime = $this->policy->mimeFor($extension);
        $declaredMime = strtolower(trim($declaredMime));
        if ($declaredMime !== $expectedMime) {
            throw new InvalidArgumentException('Declared MIME does not match the extension.');
        }
        if ($declaredSize < 1 || $declaredSize > $this->policy->maximumBytes($extension)) {
            throw new InvalidArgumentException('File size is outside the allowed limit.');
        }
        $actualSize = filesize($path);
        if (!is_int($actualSize) || $actualSize !== $declaredSize) {
            throw new InvalidArgumentException('Declared file size does not match the upload.');
        }
        $detectedMime = $this->detect($path, $extension);
        if ($detectedMime !== $expectedMime) {
            throw new InvalidArgumentException('File content does not match its type.');
        }
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || strlen($hash) !== 64) {
            throw new InvalidArgumentException('File digest is unavailable.');
        }
        return new ValidatedUpload(
            $path,
            $this->policy->typeFor($extension),
            $originalName,
            $this->safeName($originalName, $extension),
            $extension,
            $declaredMime,
            $detectedMime,
            $actualSize,
            $hash
        );
    }

    private function filename(string $name): string
    {
        if (
            $name === ''
            || preg_match('//u', $name) !== 1
            || mb_strlen($name, 'UTF-8') > $this->policy->maximumOriginalNameLength()
            || preg_match('/[\\x00-\\x1F\\x7F\\\\\/]/u', $name) === 1
            || trim($name) !== $name
            || $name[0] === '.'
            || substr($name, -1) === '.'
        ) {
            throw new InvalidArgumentException('File name is unsafe.');
        }
        $extension = (string) pathinfo($name, PATHINFO_EXTENSION);
        $base = (string) pathinfo($name, PATHINFO_FILENAME);
        if ($extension === '' || $base === '' || strpos($base, '.') !== false) {
            throw new InvalidArgumentException('Double extensions are not allowed.');
        }
        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/iD', $base) === 1) {
            throw new InvalidArgumentException('Reserved file name is not allowed.');
        }
        return $name;
    }

    private function safeName(string $originalName, string $extension): string
    {
        $base = (string) pathinfo($originalName, PATHINFO_FILENAME);
        $base = (string) preg_replace('/[^A-Za-z0-9_-]+/u', '-', $base);
        $base = trim($base, '-_');
        if ($base === '') {
            $base = 'file';
        }
        return substr($base, 0, 80) . '.' . $extension;
    }

    private function detect(string $path, string $extension): string
    {
        $head = file_get_contents($path, false, null, 0, 32);
        if (!is_string($head)) {
            throw new InvalidArgumentException('File signature is unavailable.');
        }
        $signatureMime = '';
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            $signatureMime = 'image/jpeg';
        } elseif (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) {
            $signatureMime = 'image/png';
        } elseif (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
            $signatureMime = 'image/webp';
        } elseif (strncmp($head, '%PDF-', 5) === 0) {
            $signatureMime = 'application/pdf';
        } elseif ($extension === 'docx' && $this->isDocx($path)) {
            $signatureMime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }
        if ($signatureMime === '') {
            throw new InvalidArgumentException('File signature is not allowed.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $systemMime = strtolower((string) $finfo->file($path));
        if ($extension === 'docx') {
            if (!in_array($systemMime, ['application/zip', 'application/octet-stream', $signatureMime], true)) {
                throw new InvalidArgumentException('DOCX server MIME is invalid.');
            }
        } elseif ($systemMime !== $signatureMime) {
            throw new InvalidArgumentException('Server MIME does not match the file signature.');
        }
        return $signatureMime;
    }

    private function isDocx(string $path): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        try {
            foreach (['[Content_Types].xml', '_rels/.rels', 'word/document.xml'] as $required) {
                if ($zip->locateName($required, ZipArchive::FL_NOCASE) === false) {
                    return false;
                }
            }
            $contentTypes = $zip->getFromName('[Content_Types].xml');
            $document = $zip->getFromName('word/document.xml');
            return is_string($contentTypes)
                && is_string($document)
                && strpos($contentTypes, 'content-types') !== false
                && strpos($document, 'wordprocessingml') !== false;
        } finally {
            $zip->close();
        }
    }
}

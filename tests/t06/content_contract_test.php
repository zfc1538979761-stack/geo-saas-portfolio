<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\content\foundation\ContentAssetPolicy;
use app\common\content\foundation\ContentVersionRules;
use app\common\content\foundation\EnterpriseFileValidator;
use app\common\content\foundation\SafePreview;

$passed = 0;
$failed = 0;

function t06ContractAssert(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        fwrite(STDOUT, "PASS: {$message}\n");
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL: {$message}\n");
}

function t06ContractReject(callable $operation, string $message): void
{
    try {
        $operation();
        t06ContractAssert(false, $message);
    } catch (Throwable $expected) {
        t06ContractAssert(true, $message);
    }
}

function t06TempFile(string $suffix, string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'geo-t06-');
    if ($path === false) {
        throw new RuntimeException('temporary file unavailable');
    }
    $target = $path . '.' . $suffix;
    rename($path, $target);
    file_put_contents($target, $contents);
    return $target;
}

$required = [ContentAssetPolicy::class, ContentVersionRules::class, EnterpriseFileValidator::class, SafePreview::class];
foreach ($required as $class) {
    t06ContractAssert(class_exists($class), 'T06 contract class exists: ' . $class);
}

if (count(array_filter($required, 'class_exists')) === count($required)) {
    $config = require ROOT_PATH . 'application' . DIRECTORY_SEPARATOR . 'extra'
        . DIRECTORY_SEPARATOR . 'geo_content.php';
    $policy = new ContentAssetPolicy((array) ($config['files'] ?? []));
    t06ContractAssert($policy->knowledgeTypes() === ['text', 'image', 'document'], 'knowledge type whitelist is centralized');
    t06ContractAssert($policy->maximumBytes('png') === 10 * 1024 * 1024, 'image limit is 10 MiB');
    t06ContractAssert($policy->maximumBytes('pdf') === 20 * 1024 * 1024, 'document limit is 20 MiB');
    t06ContractAssert($policy->mimeFor('jpg') === 'image/jpeg', 'JPG MIME is exact');
    t06ContractAssert($policy->mimeFor('jpeg') === 'image/jpeg', 'JPEG MIME is exact');
    t06ContractAssert($policy->mimeFor('png') === 'image/png', 'PNG MIME is exact');
    t06ContractAssert($policy->mimeFor('webp') === 'image/webp', 'WebP MIME is exact');
    t06ContractAssert($policy->mimeFor('pdf') === 'application/pdf', 'PDF MIME is exact');
    t06ContractAssert(
        $policy->mimeFor('docx') === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'DOCX MIME is exact'
    );
    t06ContractReject(static function () use ($policy): void {
        $policy->mimeFor('exe');
    }, 'unapproved extensions are rejected');

    t06ContractAssert(ContentVersionRules::articleStatuses() === ['draft', 'disabled'], 'article states are centralized');
    t06ContractAssert(ContentVersionRules::articleSource() === 'manual', 'article source is server-owned manual');
    t06ContractAssert(ContentVersionRules::canTransitionArticle('draft', 'disabled'), 'draft can transition to disabled');
    t06ContractAssert(ContentVersionRules::canTransitionArticle('disabled', 'draft'), 'disabled can transition to draft');
    t06ContractAssert(!ContentVersionRules::canTransitionArticle('draft', 'published'), 'unapproved article states are rejected');

    $validator = new EnterpriseFileValidator($policy);
    $files = [];
    try {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $files[] = $path = t06TempFile('png', (string) $png);
        $validated = $validator->validate($path, 'logo.png', 'image/png', strlen((string) $png));
        t06ContractAssert($validated->extension() === 'png' && $validated->detectedMime() === 'image/png', 'valid PNG passes signature validation');

        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABAf/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPxB//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPxB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxB//9k=', true);
        $files[] = $jpegPath = t06TempFile('jpg', (string) $jpeg);
        t06ContractAssert($validator->validate($jpegPath, 'photo.jpg', 'image/jpeg', filesize($jpegPath))->detectedMime() === 'image/jpeg', 'valid JPG passes signature validation');
        t06ContractAssert($validator->validate($jpegPath, 'photo.jpeg', 'image/jpeg', filesize($jpegPath))->extension() === 'jpeg', 'valid JPEG extension passes signature validation');

        $webp = base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAUAmJaQAA3AA/v89WAAAAA==', true);
        $files[] = $webpPath = t06TempFile('webp', (string) $webp);
        t06ContractAssert($validator->validate($webpPath, 'photo.webp', 'image/webp', filesize($webpPath))->detectedMime() === 'image/webp', 'valid WebP passes signature validation');

        $files[] = $pdfPath = t06TempFile('pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $validatedPdf = $validator->validate($pdfPath, 'brief.pdf', 'application/pdf', filesize($pdfPath));
        t06ContractAssert($validatedPdf->extension() === 'pdf', 'valid PDF signature passes');

        $files[] = $fake = t06TempFile('png', '<?php echo 1;');
        t06ContractReject(static function () use ($validator, $fake): void {
            $validator->validate($fake, 'photo.png', 'image/png', filesize($fake));
        }, 'forged image is rejected');
        t06ContractReject(static function () use ($validator, $pdfPath): void {
            $validator->validate($pdfPath, 'brief.png', 'image/png', filesize($pdfPath));
        }, 'extension and real content mismatch is rejected');
        foreach (['report.final.pdf', '../report.pdf', "bad\0.pdf", '.hidden.pdf', 'CON.pdf', 'script.php.pdf'] as $unsafe) {
            t06ContractReject(static function () use ($validator, $pdfPath, $unsafe): void {
                $validator->validate($pdfPath, $unsafe, 'application/pdf', filesize($pdfPath));
            }, 'unsafe or double-extension filename is rejected');
        }
        t06ContractReject(static function () use ($validator, $pdfPath): void {
            $validator->validate($pdfPath, 'brief.pdf', 'application/pdf', 20 * 1024 * 1024 + 1);
        }, 'oversized document is rejected');
        t06ContractReject(static function () use ($validator, $path): void {
            $validator->validate($path, 'logo.png', 'application/octet-stream', filesize($path));
        }, 'forged client MIME is rejected');
        t06ContractReject(static function () use ($validator, $path): void {
            $validator->validate($path, 'logo.png', 'image/png', 10 * 1024 * 1024 + 1);
        }, 'oversized image is rejected');

        $zipPath = tempnam(sys_get_temp_dir(), 'geo-t06-zip-');
        if ($zipPath === false) {
            throw new RuntimeException('temporary zip unavailable');
        }
        $files[] = $zipPath;
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('random.txt', 'not a document');
        $zip->close();
        t06ContractReject(static function () use ($validator, $zipPath): void {
            $validator->validate(
                $zipPath,
                'fake.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                filesize($zipPath)
            );
        }, 'ordinary ZIP is rejected as DOCX');

        $docxPath = tempnam(sys_get_temp_dir(), 'geo-t06-docx-');
        if ($docxPath === false) {
            throw new RuntimeException('temporary docx unavailable');
        }
        $files[] = $docxPath;
        $docx = new ZipArchive();
        $docx->open($docxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $docx->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $docx->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        $docx->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>');
        $docx->close();
        $validatedDocx = $validator->validate(
            $docxPath,
            'company.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            filesize($docxPath)
        );
        t06ContractAssert($validatedDocx->detectedMime() === $policy->mimeFor('docx'), 'OOXML DOCX structure passes');
    } finally {
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    $preview = SafePreview::plainText("<script>alert(1)</script>\n<img src=x onerror=alert(2)>");
    t06ContractAssert(strpos($preview, '<script>') === false, 'preview escapes script elements');
    t06ContractAssert(strpos($preview, 'onerror=') !== false && strpos($preview, '<img') === false, 'preview renders event attributes as inert text');
    t06ContractAssert(strpos($preview, '<br') !== false, 'preview preserves line breaks safely');
}

fwrite(STDOUT, "RESULT: {$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);

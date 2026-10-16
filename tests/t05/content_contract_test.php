<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\content\ContentRules;
use app\common\content\ContentTextNormalizer;

$passed = 0;
$failed = 0;

function t05ContractAssert(bool $condition, string $message): void
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

function t05ContractReject(callable $operation, string $message): void
{
    try {
        $operation();
        t05ContractAssert(false, $message);
    } catch (Throwable $expected) {
        t05ContractAssert(true, $message);
    }
}

t05ContractAssert(class_exists(ContentRules::class), 'central content rules class exists');
t05ContractAssert(class_exists(ContentTextNormalizer::class), 'central text normalizer exists');

if (class_exists(ContentRules::class) && class_exists(ContentTextNormalizer::class)) {
    t05ContractAssert(ContentRules::KEYWORD_MAX_LENGTH === 191, 'keyword limit is centralized');
    t05ContractAssert(ContentRules::QUESTION_MAX_LENGTH === 2000, 'question limit is centralized');
    t05ContractAssert(ContentRules::MAX_BATCH_IDS === 100, 'batch limit is centralized');
    t05ContractAssert(
        ContentRules::statuses() === ['normal', 'disabled'],
        'content status reuses normal and disabled'
    );
    t05ContractAssert(ContentRules::QUESTION_SOURCE_MANUAL === 'manual', 'manual source is centralized');

    $normalizer = new ContentTextNormalizer();
    t05ContractAssert($normalizer->keyword('  GEO优化  ') === 'GEO优化', 'keyword trims edge spaces');
    t05ContractAssert($normalizer->question('  GEO是什么？  ') === 'GEO是什么？', 'question trims edge spaces');
    t05ContractAssert(strlen($normalizer->questionDigest('问题')) === 32, 'question digest is binary SHA-256');
    t05ContractAssert(
        $normalizer->questionDigest('  SAME Question  ') === $normalizer->questionDigest('same question'),
        'question digest normalizes trim and case'
    );

    foreach (["", "   ", "bad\nvalue", "bad\tvalue", "bad\u{200B}value", "bad\u{00A0}value"] as $invalid) {
        t05ContractReject(static function () use ($normalizer, $invalid): void {
            $normalizer->keyword($invalid);
        }, 'keyword rejects empty, control, invisible, or abnormal whitespace');
        t05ContractReject(static function () use ($normalizer, $invalid): void {
            $normalizer->question($invalid);
        }, 'question rejects empty, control, invisible, or abnormal whitespace');
    }
    t05ContractReject(static function () use ($normalizer): void {
        $normalizer->keyword(str_repeat('a', ContentRules::KEYWORD_MAX_LENGTH + 1));
    }, 'overlong keyword is rejected');
    t05ContractReject(static function () use ($normalizer): void {
        $normalizer->question(str_repeat('a', ContentRules::QUESTION_MAX_LENGTH + 1));
    }, 'overlong question is rejected');
    t05ContractReject(static function () use ($normalizer): void {
        $normalizer->status('ai');
    }, 'unknown status is rejected');
}

fwrite(STDOUT, "RESULT: {$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);

<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\company\CompanyContext;
use app\common\company\CompanyProfileValidator;
use app\common\company\OpenSslContactCipher;

final class T04ContractFailure extends RuntimeException
{
}

function t04ContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T04ContractFailure($message);
    }
}

function t04ExpectInvalid(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (\app\common\company\CompanyException $expected) {
        return;
    }
    throw new T04ContractFailure($message);
}

try {
    $validator = new CompanyProfileValidator();
    $minimal = $validator->validate([
        'company_name' => '  测试企业  ',
        'brand' => '',
        'industry' => '',
        'products_services' => [],
        'website' => '',
        'introduction' => '',
        'mobile' => '',
        'email' => '',
    ]);
    t04ContractAssert($minimal['company_name'] === '测试企业', 'company name must be normalized');
    t04ContractAssert($minimal['brand'] === null, 'empty optional values must normalize to null');
    t04ContractAssert($minimal['products_services'] === [], 'products must remain a stable array');

    t04ExpectInvalid(static function () use ($validator): void {
        $validator->validate(['company_name' => '']);
    }, 'empty company name must fail');
    foreach (['javascript:alert(1)', 'data:text/html,test', 'ftp://example.com', 'not-a-url'] as $url) {
        t04ExpectInvalid(static function () use ($validator, $url): void {
            $validator->validate(['company_name' => '企业', 'website' => $url]);
        }, 'unsafe website scheme must fail');
    }
    t04ExpectInvalid(static function () use ($validator): void {
        $validator->validate([
            'company_name' => '企业',
            'products_services' => ['GEO', 'geo'],
        ]);
    }, 'duplicate products must fail');
    t04ExpectInvalid(static function () use ($validator): void {
        $validator->validate(['company_name' => '企业', 'mobile' => '123']);
    }, 'invalid mobile must fail');
    t04ExpectInvalid(static function () use ($validator): void {
        $validator->validate(['company_name' => '企业', 'email' => 'invalid@']);
    }, 'invalid email must fail');
    t04ExpectInvalid(static function () use ($validator): void {
        $validator->validate(['company_name' => '企业', 'company_id' => 999]);
    }, 'body company_id must be rejected');
    t04ExpectInvalid(static function () use ($validator): void {
        $validator->validate(['company_name' => "\xC3\x28"]);
    }, 'malformed UTF-8 text must be rejected');
    foreach ([
        ['company_name' => str_repeat('a', 192)],
        ['company_name' => '企业', 'brand' => str_repeat('b', 101)],
        ['company_name' => '企业', 'industry' => str_repeat('i', 101)],
        ['company_name' => '企业', 'introduction' => str_repeat('x', 5001)],
        ['company_name' => '企业', 'products_services' => [str_repeat('p', 101)]],
        ['company_name' => '企业', 'products_services' => array_fill(0, 51, 'product')],
        ['company_name' => '企业', 'products_services' => ['name' => 'product']],
    ] as $invalidLength) {
        t04ExpectInvalid(static function () use ($validator, $invalidLength): void {
            $validator->validate($invalidLength);
        }, 'length, count, and structure limits must fail');
    }
    $normalizedContact = $validator->validate([
        'company_name' => '企业',
        'mobile' => '+86 (138) 0013-8000',
        'email' => ' Owner@Example.Test ',
    ]);
    t04ContractAssert(
        $normalizedContact['contacts']['mobile'] === '+8613800138000',
        'mobile normalization is stable'
    );
    t04ContractAssert(
        $normalizedContact['contacts']['email'] === 'owner@example.test',
        'email normalization is stable'
    );

    $key = 'base64:' . base64_encode(random_bytes(32));
    $cipher = new OpenSslContactCipher($key);
    $contacts = ['mobile' => '+8613800138000', 'email' => 'owner@example.test'];
    $encrypted = $cipher->encrypt($contacts, 7);
    t04ContractAssert(strpos($encrypted, $contacts['mobile']) === false, 'mobile must not appear in ciphertext');
    t04ContractAssert(strpos($encrypted, $contacts['email']) === false, 'email must not appear in ciphertext');
    t04ContractAssert($cipher->decrypt($encrypted, 7) === $contacts, 'authorized tenant can decrypt contacts');
    t04ExpectInvalid(static function () use ($cipher, $encrypted): void {
        $cipher->decrypt($encrypted, 8);
    }, 'ciphertext must be bound to its tenant');
    t04ExpectInvalid(static function (): void {
        new OpenSslContactCipher('not-a-valid-key');
    }, 'missing or invalid encryption key fails closed');

    $context = CompanyContext::fromProfile([
        'company_id' => 7,
        'company_name' => '测试企业',
        'brand' => null,
        'industry' => null,
        'products_services' => [],
        'website' => null,
        'introduction' => null,
    ])->toArray();
    $expectedKeys = [
        'company_id',
        'company_name',
        'brand',
        'industry',
        'products_services',
        'website',
        'introduction',
        'profile_completeness',
        'missing_fields',
        'ai_ready',
    ];
    t04ContractAssert(array_keys($context) === $expectedKeys, 'AI context keys must be stable');
    t04ContractAssert(!array_key_exists('contacts', $context), 'AI context must omit contacts');
    t04ContractAssert($context['profile_completeness'] === 13, 'minimal profile completeness must be deterministic');
    t04ContractAssert($context['ai_ready'] === false, 'minimal profile is not AI-ready');

    fwrite(STDOUT, "PASS: T04 validation, encryption, completeness, and context contracts\n");
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'FAIL: ' . get_class($exception)
        . ' file=' . basename($exception->getFile())
        . ' line=' . (string) $exception->getLine()
        . ' message=' . preg_replace(
            '/(?:mobile|email|contact|password|token|secret|key)\s*[=:]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $exception->getMessage()
        )
        . PHP_EOL
    );
    exit(1);
}

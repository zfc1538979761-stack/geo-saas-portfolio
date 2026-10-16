<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\foundation\SensitiveDataRedactor;
use app\common\tenant\TenantResourceCatalog;

final class T04SourceGuardFailure extends RuntimeException
{
}

function t04SourceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T04SourceGuardFailure($message);
    }
}

try {
    $resourceNames = TenantResourceCatalog::registeredResourceNames();
    t04SourceAssert(
        array_values(array_intersect(['company_profiles', 'keywords', 'questions'], $resourceNames))
            === ['company_profiles', 'keywords', 'questions'],
        'tenant catalog retains the T04 profile and T05 content resources'
    );
    $migration = file_get_contents(
        ROOT_PATH . 'database' . DIRECTORY_SEPARATOR . 'migrations'
        . DIRECTORY_SEPARATOR . '202607300004_create_company_profiles.php'
    );
    t04SourceAssert(is_string($migration), 'T04 migration is readable');
    foreach (['keywords', 'questions', 'knowledge', 'license', 'identity_card', 'attachment', 'upload'] as $future) {
        t04SourceAssert(
            stripos($migration, 'CREATE TABLE ' . $future) === false,
            'T04 migration must not create later resource: ' . $future
        );
    }
    t04SourceAssert(
        substr_count($migration, 'CREATE TABLE ') === 1,
        'T04 migration creates exactly one table'
    );

    $service = file_get_contents(
        APP_PATH . 'common' . DIRECTORY_SEPARATOR . 'company'
        . DIRECTORY_SEPARATOR . 'CompanyProfileService.php'
    );
    t04SourceAssert(is_string($service), 'company profile service is readable');
    t04SourceAssert(strpos($service, 'Db::') === false, 'service does not bypass the tenant gateway with Db');
    t04SourceAssert(strpos($service, '->prepare(') === false, 'service does not issue raw profile SQL');
    t04SourceAssert(
        strpos($service, "gateway->list('company_profiles'") !== false
        && strpos($service, "gateway->insert('company_profiles'") !== false
        && strpos($service, "gateway->update('company_profiles'") !== false,
        'profile create/read/update share the T03 tenant gateway'
    );
    t04SourceAssert(
        strpos($service, 'companyGateway->findForUpdate($context)') !== false,
        'profile save serializes first creation by locking the trusted company row'
    );

    $api = file_get_contents(
        APP_PATH . 'api' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'Company.php'
    );
    t04SourceAssert(is_string($api), 'company API controller is readable');
    foreach (['HTTP_X_COMPANY_ID', 'HTTP_COMPANY_ID', "->get('company_id'", "->header('company_id'"] as $unsafe) {
        t04SourceAssert(strpos($api, $unsafe) === false, 'API must not derive tenant from untrusted source');
    }
    t04SourceAssert(
        strpos($api, "array_key_exists('company_id', \$posted)") !== false,
        'body company_id is explicitly rejected through validation'
    );
    t04SourceAssert(
        strpos($api, 'function context') === false,
        'AI company context is not exposed as a public endpoint'
    );

    $javascript = file_get_contents(
        ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'js'
        . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'company-profile.js'
    );
    t04SourceAssert(is_string($javascript), 'profile page JavaScript is readable');
    foreach (['innerHTML', 'outerHTML', 'document.write', 'eval(', 'localStorage', 'sessionStorage'] as $unsafeDom) {
        t04SourceAssert(
            stripos($javascript, $unsafeDom) === false,
            'profile page avoids unsafe DOM or persistent token storage'
        );
    }
    t04SourceAssert(
        strpos($javascript, '.textContent') !== false && strpos($javascript, '.value') !== false,
        'profile page renders saved text through safe DOM properties'
    );
    $stylesheet = file_get_contents(
        ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css'
        . DIRECTORY_SEPARATOR . 'company-profile.css'
    );
    t04SourceAssert(
        is_string($stylesheet) && strpos($stylesheet, 'box-sizing: border-box') !== false,
        'profile page container includes padding in responsive width'
    );

    $redacted = SensitiveDataRedactor::redact([
        'mobile' => '+8613800138000',
        'email' => 'owner@example.test',
        'contacts_ciphertext' => 'cipher-envelope',
        'contacts_modified' => true,
    ]);
    t04SourceAssert($redacted['mobile'] === '[REDACTED]', 'mobile audit key is redacted');
    t04SourceAssert($redacted['email'] === '[REDACTED]', 'email audit key is redacted');
    t04SourceAssert(
        $redacted['contacts_ciphertext'] === '[REDACTED]',
        'contact ciphertext audit key is redacted'
    );
    t04SourceAssert(
        $redacted['contacts_modified'] === true,
        'non-sensitive contact modification flag remains auditable'
    );

    fwrite(
        STDOUT,
        "PASS: T04 tenant onboarding retention, internal context, safe DOM, and sensitive redaction guards\n"
    );
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'FAIL: ' . get_class($exception)
        . ' file=' . basename($exception->getFile())
        . ' line=' . (string) $exception->getLine()
        . ' message=' . $exception->getMessage()
        . PHP_EOL
    );
    exit(1);
}

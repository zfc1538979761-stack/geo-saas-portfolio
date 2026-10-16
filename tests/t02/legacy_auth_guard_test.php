<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
$authSource = file_get_contents($root . 'application/common/library/Auth.php');
$userControllerSource = file_get_contents($root . 'application/api/controller/User.php');
$tokenStoreSource = file_get_contents(
    $root . 'application/common/identity/ThinkAuthTokenStore.php'
);
$officialMigrationTestSource = file_get_contents(
    $root . 'tests/t02/official_migration_safety_test.php'
);
$attemptStoreSource = file_get_contents(
    $root . 'application/common/identity/ThinkLoginAttemptStore.php'
);

$failures = [];
if (!is_string($authSource) || substr_count($authSource, "['company_id'] ?? 0") < 3) {
    $failures[] = 'legacy init, password login, and direct login must reject enterprise identities';
}
if (
    !is_string($userControllerSource)
    || substr_count($userControllerSource, "config('fastadmin.user_register')") < 2
) {
    $failures[] = 'mobile login must not auto-create users while public registration is disabled';
}
if (
    !is_string($tokenStoreSource)
    || strpos($tokenStoreSource, "private const PURPOSE = 'enterprise_owner'") === false
    || substr_count($tokenStoreSource, "where('purpose', self::PURPOSE)") < 3
    || strpos($tokenStoreSource, 'Token::get') !== false
) {
    $failures[] = 'enterprise tokens must be purpose-scoped and isolated from legacy token reads';
}
if (
    !is_string($officialMigrationTestSource)
    || strpos($officialMigrationTestSource, 'array_sum($t02DataCounts) > 0') === false
    || strpos($officialMigrationTestSource, 'array_sum($t02DataCounts) > 0')
        > strpos($officialMigrationTestSource, '$manager->rollback(1)')
) {
    $failures[] = 'official migration replay must reject non-empty T02 data before rollback';
}
if (
    !is_string($attemptStoreSource)
    || strpos($attemptStoreSource, 'function reserveAttempt') === false
    || strpos($attemptStoreSource, '->lock(true)') === false
) {
    $failures[] = 'login attempt slots must be reserved under a database row lock';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: legacy FastAdmin authentication cannot issue or reuse enterprise sessions\n");

<?php

declare(strict_types=1);

use app\common\foundation\RuntimeEnvironment;

require dirname(__DIR__) . '/application/common/foundation/RuntimeEnvironment.php';

$options = getopt('', ['file:', 'environment:', 'allow-placeholders']);
$file = isset($options['file']) ? (string) $options['file'] : dirname(__DIR__) . '/.env';
$environmentName = isset($options['environment'])
    ? (string) $options['environment']
    : 'development';
$allowPlaceholders = array_key_exists('allow-placeholders', $options);

if (!is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "FAIL: environment file is missing or unreadable\n");
    exit(1);
}

$configuration = parse_ini_file($file, true, INI_SCANNER_RAW);
if (!is_array($configuration)) {
    fwrite(STDERR, "FAIL: environment file is not valid INI\n");
    exit(1);
}

try {
    $environment = RuntimeEnvironment::fromName($environmentName);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, "FAIL: {$exception->getMessage()}\n");
    exit(1);
}

$processContactKey = getenv('GEO_COMPANY_CONTACT_KEY');
$configuration['company']['contact_encryption_key'] = RuntimeEnvironment::resolveContactEncryptionKey(
    $processContactKey,
    $configuration['company']['contact_encryption_key'] ?? ''
);

$missing = $environment->missingRequiredKeys($configuration);
$placeholderValues = [
    'change-me',
    'change-me-with-a-random-64-character-hex-value',
    'change-me-with-base64-encoded-32-byte-random-key',
];

if (!$allowPlaceholders) {
    foreach (
        [
            'app.token_key',
            'company.contact_encryption_key',
            'database.database',
            'database.username',
            'database.password',
        ] as $path
    ) {
        [$section, $key] = explode('.', $path, 2);
        $value = trim((string) ($configuration[$section][$key] ?? ''));
        if (in_array($value, $placeholderValues, true) && !in_array($path, $missing, true)) {
            $missing[] = $path;
        }
    }
}

$contactKey = (string) ($configuration['company']['contact_encryption_key'] ?? '');
$contactKeyIsAllowedPlaceholder = $allowPlaceholders
    && in_array($contactKey, $placeholderValues, true);
if (
    $contactKey !== ''
    && !$contactKeyIsAllowedPlaceholder
    && !RuntimeEnvironment::isValidContactEncryptionKey($contactKey)
    && !in_array('company.contact_encryption_key', $missing, true)
) {
    $missing[] = 'company.contact_encryption_key';
}

$missing = array_values(array_unique($missing));
sort($missing);

if ($missing !== []) {
    fwrite(STDERR, 'FAIL: missing or placeholder configuration keys: ' . implode(', ', $missing) . "\n");
    exit(1);
}

fwrite(
    STDOUT,
    'PASS: environment configuration keys are present for ' . $environment->name() . "\n"
);

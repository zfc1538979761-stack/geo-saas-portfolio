<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);

final class T04EnvironmentKeyFailure extends RuntimeException
{
}

function t04EnvironmentKeyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T04EnvironmentKeyFailure($message);
    }
}

function t04RunEnvironmentCheck(string $file, $processOverride): array
{
    $childEnvironment = getenv();
    if (!is_array($childEnvironment)) {
        $childEnvironment = [];
    }
    if ($processOverride === null) {
        unset($childEnvironment['GEO_COMPANY_CONTACT_KEY']);
    } else {
        $childEnvironment['GEO_COMPANY_CONTACT_KEY'] = $processOverride;
    }
    $command = [
        PHP_BINARY,
        ROOT_PATH . 'scripts' . DIRECTORY_SEPARATOR . 'check-env.php',
        '--file=' . $file,
        '--environment=testing',
    ];
    $pipes = [];
    $process = proc_open(
        $command,
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        ROOT_PATH,
        $childEnvironment
    );
    if (!is_resource($process)) {
        throw new T04EnvironmentKeyFailure('could not start environment preflight');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [
        'exit' => proc_close($process),
        'output' => (string) $stdout . (string) $stderr,
    ];
}

$temporaryFile = tempnam(sys_get_temp_dir(), 'geo-t04-env-');
if (!is_string($temporaryFile)) {
    fwrite(STDERR, "FAIL: could not create environment test fixture\n");
    exit(1);
}

$validIniKey = 'base64:' . base64_encode(random_bytes(32));
$validOverride = 'base64:' . base64_encode(random_bytes(32));
$fixture = static function (string $key): string {
    return "[app]\n"
        . "token_key = configured-for-test\n\n"
        . "[company]\n"
        . 'contact_encryption_key = ' . $key . "\n\n"
        . "[database]\n"
        . "hostname = 127.0.0.1\n"
        . "database = geo_t04_environment_test\n"
        . "username = test-only\n"
        . "password = test-only\n"
        . "hostport = 3306\n";
};

try {
    file_put_contents($temporaryFile, $fixture($validIniKey));
    $iniFallback = t04RunEnvironmentCheck($temporaryFile, null);
    t04EnvironmentKeyAssert($iniFallback['exit'] === 0, 'valid INI fallback must pass preflight');

    file_put_contents($temporaryFile, $fixture('invalid-ini-key'));
    $processWins = t04RunEnvironmentCheck($temporaryFile, $validOverride);
    t04EnvironmentKeyAssert($processWins['exit'] === 0, 'valid process override must win');

    file_put_contents($temporaryFile, $fixture($validIniKey));
    foreach ([' ' . $validOverride, 'malformed-process-key'] as $invalidOverride) {
        $result = t04RunEnvironmentCheck($temporaryFile, $invalidOverride);
        t04EnvironmentKeyAssert($result['exit'] !== 0, 'invalid defined process override must fail');
        t04EnvironmentKeyAssert(
            strpos($result['output'], 'company.contact_encryption_key') !== false,
            'preflight failure exposes only the configuration key name'
        );
        t04EnvironmentKeyAssert(
            strpos($result['output'], $validOverride) === false,
            'preflight output must not expose the configured key'
        );
    }

    fwrite(STDOUT, "PASS: T04 contact key preflight and runtime precedence contracts\n");
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
} finally {
    if (is_file($temporaryFile)) {
        unlink($temporaryFile);
    }
}

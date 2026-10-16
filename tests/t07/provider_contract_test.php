<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\ai\AiCostCalculator;
use app\common\ai\AiGenerationRequest;
use app\common\ai\AiProvider;
use app\common\ai\AiProviderFactory;
use app\common\ai\ArticleOutputParser;
use app\common\ai\DoubaoArkProvider;
use app\common\ai\MockAiProvider;
use app\common\ai\exception\AiProviderException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

$passed = 0;
$failed = 0;

function t07ProviderAssert(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
        return;
    }
    $failed++;
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
}

function t07ProviderReject(callable $operation, string $category, bool $retryable, string $message): void
{
    try {
        $operation();
        t07ProviderAssert(false, $message);
    } catch (AiProviderException $exception) {
        t07ProviderAssert(
            $exception->category() === $category && $exception->retryable() === $retryable,
            $message
        );
    } catch (Throwable $exception) {
        t07ProviderAssert(false, $message . ' wrong exception=' . get_class($exception));
    }
}

$required = [
    AiProvider::class,
    AiGenerationRequest::class,
    MockAiProvider::class,
    DoubaoArkProvider::class,
    AiProviderFactory::class,
    ArticleOutputParser::class,
    AiCostCalculator::class,
];
foreach ($required as $class) {
    t07ProviderAssert(class_exists($class) || interface_exists($class), 'T07 provider class exists: ' . $class);
}

if (count(array_filter($required, static function (string $class): bool {
    return class_exists($class) || interface_exists($class);
})) === count($required)) {
    $request = new AiGenerationRequest('system rule', 'safe synthetic input', 128);
    $mock = new MockAiProvider('Mock title', 'Mock body');
    $mockResult = $mock->generate($request);
    t07ProviderAssert($mockResult->providerCode() === 'mock', 'Mock uses the unified provider result');
    t07ProviderAssert(
        $mockResult->rawText() === '{"title":"Mock title","content":"Mock body"}',
        'Mock output is deterministic structured JSON'
    );

    $history = [];
    $handler = new MockHandler([
        new Response(
            200,
            ['x-request-id' => 'req-t07-unit'],
            '{"id":"chat-unit","choices":[{"message":{"role":"assistant","content":"{\\"title\\":\\"Ark title\\",\\"content\\":\\"Ark body\\"}"}}],"usage":{"prompt_tokens":11,"completion_tokens":7,"total_tokens":18}}'
        ),
    ]);
    $stack = HandlerStack::create($handler);
    $stack->push(Middleware::history($history));
    $ark = new DoubaoArkProvider([
        'base_url' => 'https://api.example.invalid/demo',
        'api_key' => 'ark-unit-placeholder-not-secret',
        'endpoint_id' => 'ep-unit-endpoint',
        'model_name' => 'Doubao unit model',
        'connect_timeout' => 3.0,
        'request_timeout' => 9.0,
    ], new Client(['handler' => $stack]), true);
    $arkResult = $ark->generate($request);
    $sent = $history[0]['request'];
    $options = $history[0]['options'];
    $sentPayload = json_decode((string) $sent->getBody(), true);
    t07ProviderAssert(
        (string) $sent->getUri() === 'https://api.example.invalid/demo/chat/completions',
        'Ark uses the verified official Chat Completions URL'
    );
    t07ProviderAssert(
        $sent->getHeaderLine('Authorization') === 'Bearer ark-unit-placeholder-not-secret',
        'Ark uses API Key Bearer authentication'
    );
    t07ProviderAssert(
        ($sentPayload['model'] ?? null) === 'ep-unit-endpoint'
        && ($sentPayload['messages'][0]['role'] ?? null) === 'system'
        && ($sentPayload['messages'][1]['role'] ?? null) === 'user'
        && ($sentPayload['stream'] ?? true) === false
        && ($sentPayload['max_tokens'] ?? null) === 128
        && ($sentPayload['thinking']['type'] ?? null) === 'disabled'
        && ($sentPayload['temperature'] ?? null) === 0,
        'Ark sends configured Endpoint ID, messages, non-stream mode, and bounded output'
    );
    t07ProviderAssert(
        ($options['allow_redirects'] ?? true) === false
        && ($options['http_errors'] ?? true) === false
        && ($options['verify'] ?? false) !== false
        && ($options['connect_timeout'] ?? null) === 3.0
        && ($options['timeout'] ?? null) === 9.0,
        'Ark enforces TLS and configured timeouts without redirects'
    );
    t07ProviderAssert(
        $arkResult->requestId() === 'req-t07-unit'
        && $arkResult->inputTokens() === 11
        && $arkResult->outputTokens() === 7
        && $arkResult->latencyMs() >= 0,
        'Ark result records request ID, token usage, and latency'
    );

    $errorCases = [
        [401, 'authentication_failed', false],
        [403, 'permission_denied', false],
        [429, 'rate_limited', true],
        [500, 'provider_server_error', true],
    ];
    foreach ($errorCases as [$status, $category, $retryable]) {
        $errorProvider = new DoubaoArkProvider([
            'base_url' => 'https://api.example.invalid/demo',
            'api_key' => 'ark-unit-placeholder-not-secret',
            'endpoint_id' => 'ep-unit-endpoint',
            'model_name' => 'Doubao unit model',
            'connect_timeout' => 1.0,
            'request_timeout' => 1.0,
        ], new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response($status, ['x-request-id' => 'req-private'], '{"error":{"message":"raw private response"}}'),
        ]))]), true);
        t07ProviderReject(
            static function () use ($errorProvider, $request): void { $errorProvider->generate($request); },
            $category,
            $retryable,
            'HTTP ' . $status . ' has the correct retry classification'
        );
    }

    $connectRequest = new Request('POST', 'https://api.example.invalid/demo/chat/completions');
    $timeoutProvider = new DoubaoArkProvider([
        'base_url' => 'https://api.example.invalid/demo',
        'api_key' => 'ark-unit-placeholder-not-secret',
        'endpoint_id' => 'ep-unit-endpoint',
        'model_name' => 'Doubao unit model',
        'connect_timeout' => 1.0,
        'request_timeout' => 1.0,
    ], new Client(['handler' => HandlerStack::create(new MockHandler([
        new ConnectException('private timeout detail', $connectRequest, null, ['errno' => 28]),
    ]))]), true);
    t07ProviderReject(
        static function () use ($timeoutProvider, $request): void { $timeoutProvider->generate($request); },
        'timeout',
        true,
        'transport timeout is retryable and normalized'
    );

    $parser = new ArticleOutputParser(191, 10000);
    $parsed = $parser->parse('{"title":"  Valid title  ","content":"Valid body"}');
    t07ProviderAssert($parsed === ['title' => 'Valid title', 'content' => 'Valid body'], 'article JSON is strictly parsed');
    foreach ([
        'not json',
        '{"title":"","content":"body"}',
        '{"title":"title","content":""}',
        '{"title":"title","content":"body","secret":"extra"}',
        '```json {"title":"title","content":"body"} ```',
    ] as $invalid) {
        t07ProviderReject(
            static function () use ($parser, $invalid): void { $parser->parse($invalid); },
            'invalid_response',
            false,
            'invalid or unsafe article structure is rejected'
        );
    }

    $unpriced = new AiCostCalculator(null, null, 'CNY');
    t07ProviderAssert($unpriced->calculate(1000, 500) === null, 'unconfirmed pricing remains unknown instead of zero');
    $priced = new AiCostCalculator('2.000000', '8.000000', 'CNY');
    t07ProviderAssert($priced->calculate(1000, 500) === '0.006000', 'confirmed per-million token pricing uses exact decimal math');

    $mockFactory = AiProviderFactory::create([
        'provider' => 'mock',
        'environment' => 'testing',
        'mock' => ['title' => 'Factory title', 'content' => 'Factory body'],
    ]);
    t07ProviderAssert($mockFactory instanceof MockAiProvider, 'factory selects Mock only in testing/development');
    $arkFactory = AiProviderFactory::create([
        'provider' => 'doubao_ark', 'environment' => 'testing',
        'ark' => [
            'base_url' => 'https://api.example.invalid/demo',
            'api_key' => 'ark-factory-placeholder-not-secret',
            'endpoint_id' => 'ep-factory-endpoint', 'model_name' => 'Doubao factory model',
            'connect_timeout' => 1.0, 'request_timeout' => 2.0,
        ],
    ]);
    t07ProviderAssert($arkFactory instanceof DoubaoArkProvider, 'Ark factory constructs without an undeclared CA package');
    t07ProviderReject(
        static function (): void {
            AiProviderFactory::create(['provider' => 'mock', 'environment' => 'production']);
        },
        'configuration_error',
        false,
        'production rejects Mock provider'
    );

    try {
        $leakProvider = new DoubaoArkProvider([
            'base_url' => 'https://api.example.invalid/demo',
            'api_key' => 'ark-unit-placeholder-not-secret',
            'endpoint_id' => 'ep-unit-endpoint',
            'model_name' => 'Doubao unit model',
            'connect_timeout' => 1.0,
            'request_timeout' => 1.0,
        ], new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(401, [], '{"error":{"message":"raw private response"}}'),
        ]))]), true);
        $leakProvider->generate(new AiGenerationRequest('private system', 'private company prompt', 16));
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        t07ProviderAssert(
            strpos($message, 'ark-unit-placeholder-not-secret') === false
            && strpos($message, 'ep-unit-endpoint') === false
            && strpos($message, 'private company prompt') === false
            && strpos($message, 'raw private response') === false,
            'provider exceptions exclude credentials, prompts, and raw bodies'
        );
    }
}

fwrite(STDOUT, 'RESULT: T07 provider contracts passed=' . $passed . ' failed=' . $failed . PHP_EOL);
exit($failed === 0 ? 0 : 1);

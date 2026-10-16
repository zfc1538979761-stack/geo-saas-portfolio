<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\geo\VisibilityClassifier;
use app\common\geo\visibility\UnavailableVisibilityProvider;
use app\common\geo\visibility\VisibilityProviderException;
use app\common\geo\visibility\VisibilityProviderFactory;
use app\common\geo\visibility\VisibilityRequest;
use app\common\geo\visibility\VisibilityResponse;

final class T19ProviderFailure extends RuntimeException
{
}

function t19ProviderAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T19ProviderFailure($message);
    }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}

try {
    foreach ([
        'app\\common\\geo\\VisibilityTaskDispatcher',
        'app\\common\\geo\\ThinkQueueVisibilityTaskDispatcher',
        'app\\common\\geo\\GeoVisibilityRuntimeFactory',
        'app\\common\\job\\GeoVisibilityCheckJob',
        'app\\admin\\command\\GeoVisibilitySchedule',
    ] as $class) {
        t19ProviderAssert(class_exists($class) || interface_exists($class), 'production visibility queue component exists: ' . $class);
    }

    $request = new VisibilityRequest(
        17,
        'Acme GEO',
        'Which GEO service is suitable for small businesses?',
        'doubao',
        'doubao-seed',
        ['website' => 'https://example.invalid']
    );
    t19ProviderAssert(
        $request->companyId() === 17
        && $request->brand() === 'Acme GEO'
        && $request->platform() === 'doubao'
        && $request->model() === 'doubao-seed',
        'visibility request preserves the real-provider query dimensions'
    );

    $response = new VisibilityResponse(
        'I recommend Acme GEO for a small business.',
        [['title' => 'Official', 'url' => 'https://example.invalid/about']],
        ['explicit_recommendation' => true, 'recommendation_reason' => 'It matches the requested use case.'],
        'real_provider',
        'request-1'
    );
    t19ProviderAssert(
        $response->rawAnswer() === 'I recommend Acme GEO for a small business.'
        && $response->citations()[0]['url'] === 'https://example.invalid/about'
        && $response->evidence()['explicit_recommendation'] === true,
        'visibility response keeps raw answer citations and provider evidence intact'
    );

    $classifier = new VisibilityClassifier();
    t19ProviderAssert(
        $classifier->classify('Acme GEO', new VisibilityResponse('No matching company was found.', [], [], 'fake', 'a'))['status'] === 'not_visible',
        'classifier returns not_visible when the enterprise is absent'
    );
    t19ProviderAssert(
        $classifier->classify('Acme GEO', new VisibilityResponse('Acme GEO provides GEO services.', [], [], 'fake', 'b'))['status'] === 'mentioned',
        'classifier returns mentioned for a literal enterprise mention without recommendation evidence'
    );
    $recommended = $classifier->classify('Acme GEO', $response);
    t19ProviderAssert(
        $recommended['status'] === 'recommended'
        && $recommended['recommendation_reason'] === 'It matches the requested use case.',
        'classifier requires both enterprise mention and explicit recommendation evidence'
    );

    $unavailable = new UnavailableVisibilityProvider('responses_web_search', 'ToolNotOpen');
    try {
        $unavailable->query($request);
        throw new T19ProviderFailure('unavailable provider must fail closed');
    } catch (VisibilityProviderException $exception) {
        t19ProviderAssert(
            $exception->category() === 'external_capability_unavailable'
            && $exception->providerCode() === 'responses_web_search'
            && $exception->externalCode() === 'ToolNotOpen'
            && $exception->retryable() === false
            && $exception->networkExecuted() === false,
            'ToolNotOpen is reported as a non-retryable external capability blocker'
        );
    }

    $disabled = VisibilityProviderFactory::make([
        'driver' => 'responses_web_search', 'web_search_enabled' => false, 'external_code' => 'ToolNotOpen',
    ]);
    t19ProviderAssert($disabled instanceof UnavailableVisibilityProvider, 'production factory defaults to fail-closed while Web Search permission is unavailable');
    $realCandidate = new class implements \app\common\geo\visibility\VisibilityProvider {
        public function providerCode(): string { return 'responses_web_search'; }
        public function query(VisibilityRequest $request): VisibilityResponse { return new VisibilityResponse('real adapter fixture', [], ['query_mode' => 'real_network'], $this->providerCode(), 'real-1'); }
    };
    t19ProviderAssert(
        VisibilityProviderFactory::make(['driver' => 'responses_web_search', 'web_search_enabled' => true], $realCandidate) === $realCandidate,
        'production factory can switch to a later Responses Web Search implementation without changing core services'
    );

    fwrite(STDOUT, "PASS: T19 visibility provider contracts\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

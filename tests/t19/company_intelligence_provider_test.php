<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require ROOT_PATH . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
\think\Loader::addNamespace('app', APP_PATH);

use app\common\company\intelligence\CompanyDiscoveryException;
use app\common\company\intelligence\CompanyDiscoveryProvider;
use app\common\company\intelligence\CompanyDiscoveryProviderFactory;
use app\common\company\intelligence\CompanyDiscoveryRequest;
use app\common\company\intelligence\CompanyDiscoveryResult;
use app\common\company\intelligence\CompanyProfileDraftOrganizer;
use app\common\company\intelligence\UnavailableCompanyDiscoveryProvider;
use app\common\ai\AiGenerationRequest;
use app\common\ai\AiGenerationResult;
use app\common\ai\AiProvider;

final class T19CompanyProviderFailure extends RuntimeException
{
}

function t19CompanyProviderAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new T19CompanyProviderFailure($message);
    }
    fwrite(STDOUT, 'PASS: ' . $message . PHP_EOL);
}

try {
    foreach ([
        CompanyDiscoveryProvider::class,
        CompanyDiscoveryRequest::class,
        CompanyDiscoveryResult::class,
        CompanyDiscoveryException::class,
        UnavailableCompanyDiscoveryProvider::class,
        CompanyDiscoveryProviderFactory::class,
        CompanyProfileDraftOrganizer::class,
    ] as $class) {
        t19CompanyProviderAssert(
            class_exists($class) || interface_exists($class),
            'company discovery contract exists: ' . $class
        );
    }

    $request = new CompanyDiscoveryRequest(
        '上海星河科技有限公司',
        '上海市',
        'https://xinghe.example',
        '91310000ABCDEFGH12'
    );
    t19CompanyProviderAssert(
        $request->companyName() === '上海星河科技有限公司'
        && $request->region() === '上海市'
        && $request->website() === 'https://xinghe.example'
        && $request->unifiedSocialCreditCode() === '91310000ABCDEFGH12',
        'discovery request preserves required disambiguation dimensions'
    );

    $candidate = [
        'external_key' => 'candidate-shanghai',
        'name' => '上海星河科技有限公司',
        'region' => '上海市浦东新区',
        'website' => 'https://xinghe.example',
        'unified_social_credit_code' => '91310000ABCDEFGH12',
        'public_summary' => '企业软件服务商',
        'sources' => [[
            'source_key' => 'official-site',
            'source_url' => 'https://xinghe.example/about',
            'source_title' => '关于星河科技',
            'public_excerpt' => '提供企业软件与数据服务。',
            'published_at' => null,
        ]],
    ];
    $result = new CompanyDiscoveryResult([$candidate], 'fake_company_discovery', 'request-1', false);
    t19CompanyProviderAssert(
        $result->candidates()[0]['sources'][0]['source_url'] === 'https://xinghe.example/about'
        && $result->providerCode() === 'fake_company_discovery'
        && $result->requestId() === 'request-1'
        && $result->networkExecuted() === false,
        'discovery result preserves candidate and source evidence without inventing network execution'
    );

    $unsafeCandidate = $candidate;
    $unsafeCandidate['sources'][0]['source_url'] = 'javascript://example/%0Aalert(1)';
    try {
        new CompanyDiscoveryResult([$unsafeCandidate], 'fake_company_discovery', 'request-unsafe', false);
        throw new T19CompanyProviderFailure('unsafe source URL scheme must be rejected');
    } catch (CompanyDiscoveryException $exception) {
        t19CompanyProviderAssert(
            $exception->category() === 'invalid_provider_response',
            'discovery result accepts only HTTP or HTTPS source evidence URLs'
        );
    }

    $oversizedCandidate = $candidate;
    $oversizedCandidate['external_key'] = str_repeat('x', 192);
    try {
        new CompanyDiscoveryResult([$oversizedCandidate], 'fake_company_discovery', 'request-oversized', false);
        throw new T19CompanyProviderFailure('oversized provider fields must be rejected before persistence');
    } catch (CompanyDiscoveryException $exception) {
        t19CompanyProviderAssert(
            $exception->category() === 'invalid_provider_response',
            'discovery result rejects values that exceed persistence contracts'
        );
    }

    $unavailable = new UnavailableCompanyDiscoveryProvider('responses_web_search', 'ToolNotOpen');
    try {
        $unavailable->discover($request);
        throw new T19CompanyProviderFailure('unavailable company discovery must fail closed');
    } catch (CompanyDiscoveryException $exception) {
        t19CompanyProviderAssert(
            $exception->category() === 'external_capability_unavailable'
            && $exception->providerCode() === 'responses_web_search'
            && $exception->externalCode() === 'ToolNotOpen'
            && $exception->retryable() === false
            && $exception->networkExecuted() === false
            && $exception->httpStatus() === 503,
            'ToolNotOpen returns an explicit non-retryable external capability failure'
        );
    }

    $disabled = CompanyDiscoveryProviderFactory::make([
        'driver' => 'responses_web_search',
        'web_search_enabled' => false,
        'external_code' => 'ToolNotOpen',
    ]);
    t19CompanyProviderAssert(
        $disabled instanceof UnavailableCompanyDiscoveryProvider,
        'production company discovery factory defaults to fail closed'
    );

    $futureProvider = new class implements CompanyDiscoveryProvider {
        public function providerCode(): string
        {
            return 'responses_web_search';
        }

        public function discover(CompanyDiscoveryRequest $request): CompanyDiscoveryResult
        {
            return new CompanyDiscoveryResult([], $this->providerCode(), 'future-1', true);
        }
    };
    t19CompanyProviderAssert(
        CompanyDiscoveryProviderFactory::make(
            ['driver' => 'responses_web_search', 'web_search_enabled' => true],
            $futureProvider
        ) === $futureProvider,
        'future real provider can be injected without changing the discovery service'
    );

    $organizerProvider = new class implements AiProvider {
        public function providerCode(): string { return 'fake_ai'; }
        public function generate(AiGenerationRequest $request): AiGenerationResult
        {
            return new AiGenerationResult(json_encode([
                'facts' => [
                    ['field_key' => 'company_name', 'value' => '上海星河科技有限公司', 'source_ids' => [501]],
                    ['field_key' => 'region', 'value' => '上海市浦东新区', 'source_ids' => [501]],
                    ['field_key' => 'products_services', 'value' => ['企业软件', '数据服务'], 'source_ids' => [501]],
                ],
                'inferences' => [
                    ['field_key' => 'advantages', 'value' => ['可能具备行业经验'], 'reason' => '公开简介概括'],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'fake_ai', 'fake-model', 'ai-request-1', null, null, 0);
        }
    };
    $organized = (new CompanyProfileDraftOrganizer())->organize(
        $candidate,
        [[
            'id' => 501,
            'source_url' => 'https://xinghe.example/about',
            'source_title' => '关于星河科技',
            'public_excerpt' => '提供企业软件与数据服务。',
        ]],
        $organizerProvider
    );
    t19CompanyProviderAssert(
        $organized['draft']['company_name'] === '上海星河科技有限公司'
        && $organized['facts'][0]['fact_type'] === 'source_fact'
        && $organized['facts'][0]['source_ids'] === [501]
        && $organized['inferences'][0]['fact_type'] === 'ai_inference'
        && $organized['provider_code'] === 'fake_ai',
        'profile organizer keeps sourced facts separate from unsourced AI inference'
    );

    fwrite(STDOUT, "PASS: T19 company intelligence provider contracts\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

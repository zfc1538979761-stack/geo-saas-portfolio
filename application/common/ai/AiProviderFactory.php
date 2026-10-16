<?php
declare(strict_types=1);
namespace app\common\ai;
use app\common\ai\exception\AiConfigurationException;
use app\common\portfolio\PortfolioAiProvider;
use GuzzleHttp\ClientInterface;
final class AiProviderFactory {
    public static function create(array $config,?ClientInterface $http=null): AiProvider {
        if (($config['provider']??'')!=='mock' || !in_array($config['environment']??'', ['development','testing'],true)) { throw new AiConfigurationException('Only local demo AI is available.'); }
        if (!empty($config['portfolio'])) { return new PortfolioAiProvider(); }
        $mock=(array)($config['mock']??[]);
        return new MockAiProvider((string)($mock['title']??'Mock generated title'),(string)($mock['content']??'Mock generated article body.'),isset($mock['raw'])?(string)$mock['raw']:null);
    }
}

<?php
declare(strict_types=1);
namespace app\common\company\intelligence;
use app\common\ai\AiProvider;
final class CompanyDiscoveryProviderFactory {
    public static function make(array $config,?CompanyDiscoveryProvider $realProvider=null,?AiProvider $aiFallback=null): CompanyDiscoveryProvider {
        return new UnavailableCompanyDiscoveryProvider('responses_web_search','PortfolioDemoOffline');
    }
}

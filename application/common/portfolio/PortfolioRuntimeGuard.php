<?php
declare(strict_types=1);
namespace app\common\portfolio;
final class PortfolioRuntimeGuard {
    public static function assertDemo(array $config): void {
        if (!in_array($config['environment']??'', ['development','testing'],true)) { throw new \RuntimeException('Portfolio requires development/testing.'); }
        foreach (['ai','supplier','visibility','storage'] as $key) {
            if (($config[$key]??'')!=='mock') { throw new \RuntimeException('Portfolio external driver refused: '.$key); }
        }
        $db=$config['database']??[];
        if (!in_array($db['hostname']??'', ['127.0.0.1','localhost'],true)
            || !preg_match('/^geo_portfolio_[a-z0-9_]+$/D',(string)($db['database']??''))
            || (int)($db['hostport']??0)!==33329) { throw new \RuntimeException('Portfolio database must be isolated on loopback:33329.'); }
        $redis=$config['redis']??[];
        if (!in_array($redis['hostname']??'', ['127.0.0.1','localhost'],true) || (int)($redis['select']??0)!==14) { throw new \RuntimeException('Portfolio Redis must use loopback DB 14.'); }
    }
    public static function effective(array $ini): array {
        $get=static function(string $env,$fallback) { $value=getenv($env); return $value===false?$fallback:$value; };
        return [
            'environment'=>$get('GEO_APP_ENV',$ini['app']['env']??'development'),
            'ai'=>$get('GEO_AI_PROVIDER',$ini['ai']['provider']??'mock'),
            'supplier'=>$get('GEO_SUPPLIER_DRIVER',$ini['supplier']['driver']??'mock'),
            'visibility'=>$get('GEO_VISIBILITY_DRIVER',$ini['visibility']['driver']??'mock'),
            'storage'=>$get('GEO_CONTENT_STORAGE_DRIVER',$ini['content']['storage_driver']??'mock'),
            'database'=>['hostname'=>$get('GEO_DATABASE_HOSTNAME',$ini['database']['hostname']??''),'database'=>$get('GEO_DATABASE_DATABASE',$ini['database']['database']??''),'hostport'=>$get('GEO_DATABASE_HOSTPORT',$ini['database']['hostport']??0)],
            'redis'=>['hostname'=>$get('GEO_REDIS_HOSTNAME',$ini['redis']['hostname']??'127.0.0.1'),'select'=>$get('GEO_REDIS_SELECT',$ini['redis']['select']??14)],
        ];
    }
    public static function fromFile(string $path): array {
        if (!is_file($path)) { throw new \RuntimeException('Run php scripts/setup-portfolio.php first.'); }
        $ini=parse_ini_file($path,true,INI_SCANNER_RAW);
        if (!is_array($ini)) { throw new \RuntimeException('Invalid Portfolio local config.'); }
        self::assertDemo(self::effective($ini)); return $ini;
    }
}

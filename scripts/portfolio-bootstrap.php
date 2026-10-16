<?php
declare(strict_types=1);
$portfolioRoot=dirname(__DIR__);
require_once $portfolioRoot.'/application/common/portfolio/PortfolioRuntimeGuard.php';
$portfolioIni=\app\common\portfolio\PortfolioRuntimeGuard::fromFile($portfolioRoot.'/.env');
if (getenv('GEO_APP_ENV')===false) { putenv('GEO_APP_ENV='.$portfolioIni['app']['env']); }
defined('APP_PATH') or define('APP_PATH',$portfolioRoot.'/application/');
if (!defined('THINK_VERSION')) { require $portfolioRoot.'/thinkphp/base.php'; }
\think\Loader::addNamespace('app',APP_PATH);
\think\Config::set(require APP_PATH.'config.php');
\think\Config::set('database',require APP_PATH.'database.php');
foreach (glob(APP_PATH.'extra/*.php') as $portfolioFile) { \think\Config::set(pathinfo($portfolioFile,PATHINFO_FILENAME),require $portfolioFile); }
date_default_timezone_set('Asia/Shanghai');
function portfolio_pdo(): PDO {
    $db=\think\Config::get('database');
    return new PDO('mysql:host='.$db['hostname'].';port='.$db['hostport'].';dbname='.$db['database'].';charset=utf8mb4',$db['username'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
}

set_exception_handler(static function(Throwable $error): void { fwrite(STDERR, get_class($error).": ".$error->getMessage().PHP_EOL); exit(1); });

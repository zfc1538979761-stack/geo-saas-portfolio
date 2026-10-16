<?php
require __DIR__ . '/bootstrap.php';
use app\common\portfolio\PortfolioRuntimeGuard as Guard;
check(class_exists(Guard::class), 'Portfolio guard implemented');
$valid = ['environment'=>'development','ai'=>'mock','supplier'=>'mock','visibility'=>'mock','storage'=>'mock',
    'database'=>['hostname'=>'127.0.0.1','database'=>'geo_portfolio_demo','hostport'=>33329],
    'redis'=>['hostname'=>'127.0.0.1','select'=>14]];
Guard::assertDemo($valid);
check(true, 'isolated demo configuration accepted');
foreach (['environment'=>'production','ai'=>'doubao_ark','supplier'=>'media_market','visibility'=>'responses_web_search','storage'=>'media_market'] as $key=>$bad) {
    $copy=$valid; $copy[$key]=$bad; rejects(fn()=>Guard::assertDemo($copy), 'unsafe '.$key.' rejected');
}
foreach ([['hostname','192.0.2.1'],['database','fastadmin'],['hostport',3306]] as [$key,$bad]) {
    $copy=$valid; $copy['database'][$key]=$bad; rejects(fn()=>Guard::assertDemo($copy), 'unsafe database '.$key.' rejected');
}
$ini=['app'=>['env'=>'development'],'ai'=>['provider'=>'mock'],'supplier'=>['driver'=>'mock'],
    'content'=>['storage_driver'=>'mock'],'database'=>$valid['database'],'redis'=>$valid['redis']];
foreach (['GEO_AI_PROVIDER'=>'doubao_ark','GEO_DATABASE_DATABASE'=>'fastadmin','GEO_VISIBILITY_DRIVER'=>'responses_web_search'] as $key=>$bad) {
    $old=getenv($key); putenv($key.'='.$bad);
    rejects(fn()=>Guard::assertDemo(Guard::effective($ini)), 'environment override '.$key.' rejected');
    $old===false ? putenv($key) : putenv($key.'='.$old);
}

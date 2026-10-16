<?php
require __DIR__.'/bootstrap.php';
$root=PORTFOLIO_ROOT;
foreach (['ACCOUNTS.md','HANDOVER.md','PROJECT_STATUS.md','docs/baseline','docs/mediamarket','public/router_preview.php','application/common/supplier/MediaMarketAdapter.php','application/common/supplier/MediaMarketApiClient.php','application/common/ai/DoubaoArkProvider.php'] as $path) {check(!file_exists($root.$path),'private source excluded: '.$path);}
$publicPhp=array_map('basename',glob($root.'public/*.php'));sort($publicPhp);
check($publicPhp===['index.php','portfolio-admin.php','router.php'],'only new documented Web entry points shipped');
check(strpos(file_get_contents($root.'.gitignore'),'/runtime/')!==false,'new local data excluded from release candidate');
check(strpos(file_get_contents($root.'.npmrc'),'strict-ssl=true')!==false,'TLS validation remains enabled');
$html=file_get_contents($root.'application/index/view/enterprise/index.html');
check(strpos($html,'Portfolio Demo · 虚构数据')!==false && strpos($html,'不代表真实收录效果')!==false,'demo and simulated visibility labels present');

check(preg_match('/^\.env\r?$/m',file_get_contents($root.'.gitignore'))===1,'local credentials excluded from distribution');

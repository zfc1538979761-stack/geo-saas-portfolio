<?php
require __DIR__.'/bootstrap.php';
$scratch=PORTFOLIO_ROOT.'runtime/setup-fixture-'.bin2hex(random_bytes(5));
mkdir($scratch.'/scripts',0700,true);
copy(PORTFOLIO_ROOT.'scripts/setup-portfolio.php',$scratch.'/scripts/setup-portfolio.php');
file_put_contents($scratch.'/.env.example',str_replace("\n","\r\n",str_replace("\r\n","\n",file_get_contents(PORTFOLIO_ROOT.'.env.example'))));
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($scratch.'/scripts/setup-portfolio.php'),$lines,$code);
check($code===0,'clean setup exits successfully');
$ini=@parse_ini_file($scratch.'/.env',true,INI_SCANNER_RAW);
check(is_array($ini) && isset($ini['company']['contact_encryption_key']),'framework INI parser accepts generated contact key');
check(strlen(base64_decode(substr($ini['company']['contact_encryption_key'],7),true))===32,'new contact key has 32 bytes');
check(strlen($ini['app']['token_key'])===64,'fresh HMAC key generated');
$hash=hash_file('sha256',$scratch.'/.env');
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($scratch.'/scripts/setup-portfolio.php').' 2>NUL',$again,$code);
check($code!==0 && hash_file('sha256',$scratch.'/.env')===$hash,'existing local configuration never overwritten');

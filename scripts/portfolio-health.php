<?php
require __DIR__.'/portfolio-bootstrap.php';
portfolio_pdo()->query('SELECT 1');
$q=\think\Config::get('queue');$r=new Redis();$r->connect($q['host'],$q['port'],2);
if($q['password']!==''){$r->auth($q['password']);}$r->select($q['select']);$r->ping();
echo 'PASS: isolated database and Redis available'.PHP_EOL;

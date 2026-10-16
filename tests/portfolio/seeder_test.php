<?php
require __DIR__.'/bootstrap.php';
use app\common\portfolio\PortfolioSeeder;
check(class_exists(PortfolioSeeder::class),'Portfolio seeder implemented');
require PORTFOLIO_ROOT.'scripts/portfolio-bootstrap.php';
$pdo=portfolio_pdo();
$seeder=new PortfolioSeeder();
$first=$seeder->seed($pdo,'fa_');
$second=$seeder->seed($pdo,'fa_');
check($first===$second,'repeated seed returns identical resources');
check($first['a']['company_id']!==$first['b']['company_id'],'two distinct synthetic tenants');
$count=$pdo->query("SELECT COUNT(*) FROM fa_companies WHERE name LIKE 'Portfolio Demo%'")->fetchColumn();
check((int)$count===2,'exactly two demo companies');
$difference=$pdo->query('SELECT COUNT(*) FROM fa_wallets WHERE available_balance<0 OR frozen_balance<0')->fetchColumn();
check((int)$difference===0,'synthetic wallet balances nonnegative');

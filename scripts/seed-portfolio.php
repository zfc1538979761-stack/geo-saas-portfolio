<?php
declare(strict_types=1);
require __DIR__.'/portfolio-bootstrap.php';
$pdo=portfolio_pdo();
\app\common\portfolio\PortfolioDatabaseGuard::initialize($pdo);
$hasBase=$pdo->query("SHOW TABLES LIKE 'fa_companies'")->fetchColumn();
if (!$hasBase) { $pdo->exec(file_get_contents(ROOT_PATH.'database/portfolio-base.sql')); }
(new \app\common\migration\MigrationManager($pdo,ROOT_PATH.'database/migrations','fa_'))->migrate();
$resources=(new \app\common\portfolio\PortfolioSeeder())->seed($pdo,'fa_');
echo 'PASS: demo seed ready; two synthetic tenants and virtual balances. '.json_encode(['a'=>$resources['a']['company_id'],'b'=>$resources['b']['company_id']]).PHP_EOL;

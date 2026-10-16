<?php
require __DIR__.'/portfolio-bootstrap.php';
$result=(new \app\common\portfolio\PortfolioWorkflow())->run(portfolio_pdo());
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;

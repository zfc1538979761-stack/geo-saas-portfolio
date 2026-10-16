<?php
require __DIR__.'/bootstrap.php';
require PORTFOLIO_ROOT.'scripts/portfolio-bootstrap.php';
$pdo=portfolio_pdo();
$cached=$pdo->query('SELECT result_json FROM fa_portfolio_workflow WHERE id=1')->fetchColumn();
$counts=$pdo->query('SELECT (SELECT COUNT(*) FROM fa_publish_orders) orders_count,(SELECT COUNT(*) FROM fa_ledger_entries) ledger_count')->fetch(PDO::FETCH_ASSOC);
try {
    $pdo->exec('DELETE FROM fa_portfolio_workflow WHERE id=1');
    $out=(new \app\common\portfolio\PortfolioWorkflow())->run($pdo);
    check($out['published_status']==='published' && $out['rejected_status']==='rejected','resume after interruption preserves terminal orders');
    $after=$pdo->query('SELECT (SELECT COUNT(*) FROM fa_publish_orders) orders_count,(SELECT COUNT(*) FROM fa_ledger_entries) ledger_count')->fetch(PDO::FETCH_ASSOC);
    check($counts==$after,'resume does not duplicate orders or wallet entries');
} finally {
    if ($cached && !isset($out)) {$pdo->prepare('INSERT INTO fa_portfolio_workflow(id,result_json) VALUES(1,?) ON DUPLICATE KEY UPDATE result_json=VALUES(result_json)')->execute([$cached]);}
}

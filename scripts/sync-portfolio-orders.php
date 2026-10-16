<?php
require __DIR__.'/portfolio-bootstrap.php';
$out=\app\common\publishing\PublishSyncJob::runBatch(portfolio_pdo(),'fa_');
foreach($out['results'] as $row){if($row['status']==='error'){throw new RuntimeException('Local mock order synchronization failed.');}}
echo 'PASS: local mock synchronization'.PHP_EOL;

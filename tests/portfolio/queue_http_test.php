<?php
require __DIR__.'/bootstrap.php';
require PORTFOLIO_ROOT.'scripts/portfolio-bootstrap.php';
use app\common\publishing\PublishWorkerReadiness;
check(PublishWorkerReadiness::isReady(PublishWorkerReadiness::defaultHeartbeatPath()),'publish Worker has live lifecycle lock and heartbeat');
function localRequest(string $path,array $data=[],?string $token=null,string $key=''): array {
    $headers=['Content-Type: application/x-www-form-urlencoded','Accept: application/json'];
    if ($token!==null) {$headers[]='Authorization: Bearer '.$token;}
    if ($key!=='') {$headers[]='Idempotency-Key: '.$key;}
    $ctx=stream_context_create(['http'=>['method'=>$data===[]?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>http_build_query($data),'ignore_errors'=>true,'timeout'=>10,'follow_location'=>0]]);
    $raw=file_get_contents('http://127.0.0.1:8877'.$path,false,$ctx);
    $reply=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if (!in_array($reply['code']??null,[0,1],true) || !preg_match('/\s2[0-9]{2}\s/',$http_response_header[0]??'')) { throw new RuntimeException('HTTP API failed '.$path.': '.($reply['msg']??'unknown')); }
    return $reply['data'];
}
$seed=json_decode(portfolio_pdo()->query('SELECT resources_json FROM fa_portfolio_seed WHERE id=1')->fetchColumn(),true);$a=$seed['a'];
$login=localRequest('/api/auth/login',['account'=>'portfolio_demo_a','password'=>'PortfolioDemo123!']);$token=$login['token'];
check(is_string($token)&&strlen($token)>20,'synthetic account logs in through real HTTP API');
$key='portfolio-http-'.bin2hex(random_bytes(6));
$payload=['keyword_id'=>$a['keyword_id'],'question_id'=>$a['question_id'],'prompt_template_id'=>$a['prompt_id'],'knowledge_item_ids'=>[$a['knowledge_id']],'geo_answer_version_id'=>$a['answer_id']];
$task=localRequest('/api/ai_task/create',$payload,$token,$key);$duplicate=localRequest('/api/ai_task/create',$payload,$token,$key);
check($task['id']===$duplicate['id'],'duplicate HTTP AI submission returns same task');
$deadline=microtime(true)+25;
do {usleep(200000);$state=localRequest('/api/ai_task/detail?id='.$task['id'],[],$token);}while(!in_array($state['status'],['succeeded','failed','cancelled'],true)&&microtime(true)<$deadline);
check($state['status']==='succeeded','HTTP → Redis → separate Worker → article succeeds');
$result=localRequest('/api/ai_task/result?id='.$task['id'],[],$token);
$orderData=['article_id'=>$result['article']['id'],'article_version_id'=>$result['version']['id'],'media_ids'=>[$seed['media_id']]];
$preview=localRequest('/api/order/preview',$orderData,$token);$orderData['price_fingerprint']=$preview['price_fingerprint'];
$order=localRequest('/api/order/create',$orderData,$token,$key.'-order');
$deadline=microtime(true)+25;
do {usleep(200000);$detail=localRequest('/api/order/detail?id='.$order['id'],[],$token);}while(!in_array($detail['order']['status'],['published','manual_review'],true)&&microtime(true)<$deadline);
check($detail['order']['status']==='published','HTTP order reaches published through live Worker and mock synchronization');
echo 'PASS: task_id='.$task['id'].' article_id='.$result['article']['id'].' order_id='.$order['id'].PHP_EOL;

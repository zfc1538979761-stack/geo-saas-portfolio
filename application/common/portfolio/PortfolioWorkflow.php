<?php
declare(strict_types=1);
namespace app\common\portfolio;
use PDO;
use app\common\tenant\{TenantContext,TenantDataGateway,TenantResourceCatalog,PdoAuditLogger,TenantTaskEnvelope};
use app\common\ai\task\{AiGenerationService,AiTaskDispatcher,AiTaskRuntimeFactory};
use app\common\billing\BillingService;
use app\common\publishing\{PublishOrderService,PublishSubmitService,PublishSyncService};
use app\common\supplier\MockSupplierAdapter;
use app\common\geo\{VisibilityService,VisibilityClassifier,VisibilityPublicationTimeline,VisibilityTrendService,OptimizationSuggestionService,GeoVisibilityRuntimeFactory};
use app\common\geo\visibility\MockVisibilityProvider;
final class PortfolioWorkflow {
    public function run(PDO $pdo): array {
        $seed=(new PortfolioSeeder())->seed($pdo,'fa_');$a=$seed['a'];
        $pdo->exec('CREATE TABLE IF NOT EXISTS fa_portfolio_workflow(id INT PRIMARY KEY,result_json JSON NOT NULL) ENGINE=InnoDB');
        $done=$pdo->query('SELECT result_json FROM fa_portfolio_workflow WHERE id=1')->fetchColumn();
        if ($done) { return json_decode($done,true,64,JSON_THROW_ON_ERROR); }
        $ctx=TenantContext::enterprise($a['user_id'],$a['company_id']);$audit=new PdoAuditLogger($pdo,'fa_');$gateway=new TenantDataGateway($pdo,TenantResourceCatalog::registry('fa_'),$audit);
        $dispatch=new class implements AiTaskDispatcher { public function dispatch(array $envelope,string $queue): void {} };
        $generation=new AiGenerationService($pdo,$gateway,$audit,$dispatch,AiTaskRuntimeFactory::envelope(),'fa_',AiTaskRuntimeFactory::config());
        $task=$generation->create(['keyword_id'=>$a['keyword_id'],'question_id'=>$a['question_id'],'prompt_template_id'=>$a['prompt_id'],'knowledge_item_ids'=>[$a['knowledge_id']],'geo_answer_version_id'=>$a['answer_id']], 'portfolio-workflow-article',$ctx);
        AiTaskRuntimeFactory::runner()->run($task['id'],$ctx);
        $result=$generation->result($task['id'],$ctx);$articleId=$result['article']['id'];$versionId=$result['version']['id'];
        $timeline=new VisibilityPublicationTimeline($pdo,'fa_');
        $baselineService=new VisibilityService($pdo,$gateway,$audit,new VisibilityClassifier(),static fn()=>time()-60,$timeline);
        $baseline=$baselineService->check(['question_id'=>$a['question_id'],'platform'=>'Portfolio Demo','model_name'=>'portfolio-demo','check_type'=>'baseline','idempotency_key'=>'portfolio-baseline'],new MockVisibilityProvider('【模拟结果】本次未提及该企业。',[],['query_mode'=>'portfolio_demo']),$ctx);
        $billing=new BillingService($pdo,$audit,'fa_');$orders=new PublishOrderService($pdo,$billing,'fa_');
        $preview=$orders->preview($a['company_id'],$articleId,$versionId,[$seed['media_id']]);
        $order=$orders->create($a['company_id'],$a['user_id'],$articleId,$versionId,[$seed['media_id']],'portfolio-publish-success',$preview['price_fingerprint']);
        $status=$orders->getDetail($a['company_id'],$order['id'])['order']['status'];
        if ($status==='funds_frozen') {$orders->queueForSubmit($a['company_id'],$order['id']);$status='queued';}
        $adapter=new MockSupplierAdapter('development',['scenario'=>'default']);
        if ($status==='queued') {(new PublishSubmitService($pdo,$orders,$billing,'fa_'))->submitOrder($adapter,$a['company_id'],$order['id'],'official_media',['mock-media-official'],'portfolio-submit-success');}
        if ($orders->getDetail($a['company_id'],$order['id'])['order']['status']!=='published') {(new PublishSyncService($pdo,$orders,'fa_'))->syncOrder($adapter,$a['company_id'],$order['id']);}
        $detail=$orders->getDetail($a['company_id'],$order['id']);
        if ($detail['order']['status']!=='published') { throw new \RuntimeException('Demo publication did not reach published.'); }
        $walletBefore=$billing->getWallet($a['company_id']);
        $orders->transitionItem($a['company_id'],$order['id'],$detail['items'][0]['id'],'published','portfolio_demo');
        $walletAfter=$billing->getWallet($a['company_id']);
        $duplicateUnchanged=$walletBefore['available_balance']===$walletAfter['available_balance'] && $walletBefore['frozen_balance']===$walletAfter['frozen_balance'];
        $rejectOrder=$orders->create($a['company_id'],$a['user_id'],$articleId,$versionId,[$seed['media_id']],'portfolio-reject',$preview['price_fingerprint']);
        $status=$orders->getDetail($a['company_id'],$rejectOrder['id'])['order']['status'];
        if ($status==='funds_frozen') {$orders->queueForSubmit($a['company_id'],$rejectOrder['id']);$status='queued';}
        if ($status==='queued') {(new PublishSubmitService($pdo,$orders,$billing,'fa_'))->submitOrder($adapter,$a['company_id'],$rejectOrder['id'],'official_media',['mock-media-official'],'portfolio-submit-reject');}
        if ($orders->getDetail($a['company_id'],$rejectOrder['id'])['order']['status']!=='rejected') {(new PublishSyncService($pdo,$orders,'fa_'))->syncOrder(new MockSupplierAdapter('development',['scenario'=>'reject']),$a['company_id'],$rejectOrder['id']);}
        $rejected=$orders->getDetail($a['company_id'],$rejectOrder['id']);$rejectedWallet=$billing->getWallet($a['company_id']);
        $existingPlans=$gateway->list('geo_visibility_plans',$ctx,['question_id'=>$a['question_id'],'platform'=>'Portfolio Demo','model_name'=>'portfolio-demo','provider_code'=>'mock'],1);
        $plan=$existingPlans[0]??GeoVisibilityRuntimeFactory::scheduler()->createPlan(['question_id'=>$a['question_id'],'baseline_result_id'=>$baseline['result']['id'],'platform'=>'Portfolio Demo','model_name'=>'portfolio-demo','provider_code'=>'mock','interval_days'=>3],$ctx);
        $future=time()+3*86400;
        $current=(new VisibilityService($pdo,$gateway,$audit,new VisibilityClassifier(),static fn()=>$future,$timeline))->check(['question_id'=>$a['question_id'],'platform'=>'Portfolio Demo','model_name'=>'portfolio-demo','check_type'=>'initial','plan_id'=>$plan['id'],'idempotency_key'=>'portfolio-recheck'],new MockVisibilityProvider('【模拟结果】澄屿收纳（演示）提供模块化收纳用品。',[],['query_mode'=>'portfolio_demo']),$ctx);
        $comparison=(new VisibilityTrendService($gateway,$audit))->compare($current['result']['id'],$ctx);
        $suggestion=(new OptimizationSuggestionService($gateway,$audit))->generate($current['result']['id'],new PortfolioAiProvider(),$ctx);
        $cross=$gateway->find('articles',$articleId,TenantContext::enterprise($seed['b']['user_id'],$seed['b']['company_id']))===null;
        $invalid=false;try { $baselineService->check(['question_id'=>$a['question_id'],'platform'=>'Other Demo','model_name'=>'portfolio-demo','check_type'=>'baseline','idempotency_key'=>'post-publish'],new MockVisibilityProvider('demo'),$ctx); } catch (\Throwable $e) {$invalid=true;}
        $insufficient=false;try {$billing->freeze($a['company_id'],'portfolio_test','insufficient','999999.00','portfolio-insufficient');}catch(\Throwable $e){$insufficient=true;}
        $out=['article_id'=>$articleId,'version_id'=>$versionId,'order_id'=>(int)$order['id'],'published_status'=>$detail['order']['status'],'rejected_status'=>$rejected['order']['status'],'baseline_at'=>$baseline['result']['detected_at'],'published_at'=>$timeline->firstPublishedAt($a['question_id'],$ctx),'trend'=>$comparison['trend'],'suggestion_id'=>$suggestion['id'],'cross_tenant_rejected'=>$cross,'invalid_baseline_rejected'=>$invalid,'insufficient_balance_rejected'=>$insufficient,'duplicate_capture_unchanged'=>$duplicateUnchanged,'rejection_restored_balance'=>$rejected['order']['frozen_amount']==='0.00' && $rejected['order']['status']==='rejected','provider_mode'=>'local_mock','evidence_mode'=>'local mock adapters; simulation clock advanced for recheck'];
        $pdo->prepare('INSERT INTO fa_portfolio_workflow(id,result_json) VALUES (1,?)')->execute([json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        return $out;
    }
}

<?php
declare(strict_types=1);
namespace app\common\portfolio;
use PDO;
use app\common\tenant\{TenantContext,TenantDataGateway,TenantResourceCatalog,PdoAuditLogger,TenantCompanyGateway};
use app\common\company\{CompanyProfileService,OpenSslContactCipher};
use app\common\content\{KeywordQuestionService,KnowledgeItemService,PromptTemplateService};
use app\common\content\foundation\{EnterpriseFileValidator,ContentAssetPolicy};
use app\common\geo\{QuestionGenerationService,TargetAnswerService};
use app\common\billing\BillingService;
use think\Config;
final class PortfolioSeeder {
    public function seed(PDO $pdo,string $prefix): array {
        if ($prefix!=='fa_' || strpos((string)$pdo->query('SELECT DATABASE()')->fetchColumn(),'geo_portfolio_')!==0) { throw new \RuntimeException('Seed only accepts an isolated Portfolio schema.'); }
        $has=$pdo->query("SHOW TABLES LIKE 'fa_portfolio_seed'")->fetchColumn();
        if (!$has && (int)$pdo->query('SELECT COUNT(*) FROM fa_companies')->fetchColumn()>0) { throw new \RuntimeException('Unmarked database contains existing companies.'); }
        $pdo->exec('CREATE TABLE IF NOT EXISTS fa_portfolio_seed (id INT PRIMARY KEY,state VARCHAR(20) NOT NULL,resources_json JSON NULL) ENGINE=InnoDB');
        if ((int)$pdo->query("SELECT GET_LOCK('portfolio_seed',2)")->fetchColumn()!==1) { throw new \RuntimeException('Seed already running.'); }
        try {
            $row=$pdo->query('SELECT state,resources_json FROM fa_portfolio_seed WHERE id=1')->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['state']==='complete') { return json_decode($row['resources_json'],true,64,JSON_THROW_ON_ERROR); }
            $pdo->exec("INSERT IGNORE INTO fa_portfolio_seed (id,state) VALUES (1,'initializing')");
            $allowed=['Portfolio Demo A — 澄屿收纳（虚构）','Portfolio Demo B — 岚川户外（虚构）'];
            foreach ($pdo->query('SELECT name FROM fa_companies')->fetchAll(PDO::FETCH_COLUMN) as $name) { if (!in_array($name,$allowed,true)) { throw new \RuntimeException('Non-demo company found; refusing seed.'); } }
            $out=[];
            foreach (['a','b'] as $index=>$key) {
                $now=time(); $name=$allowed[$index];$brand=$index===0?'澄屿收纳（演示）':'岚川户外（演示）';
                $query=$pdo->prepare('SELECT id FROM fa_companies WHERE name=?');$query->execute([$name]);$companyId=(int)$query->fetchColumn();
                if (!$companyId) { $pdo->prepare("INSERT INTO fa_companies(name,status,createtime,updatetime) VALUES (?,'normal',?,?)")->execute([$name,$now,$now]);$companyId=(int)$pdo->lastInsertId(); }
                $username='portfolio_demo_'.$key;
                $query=$pdo->prepare('SELECT id FROM fa_user WHERE username=?');$query->execute([$username]);$userId=(int)$query->fetchColumn();
                if (!$userId) { $pdo->prepare("INSERT INTO fa_user(username,nickname,company_id,password_hash,role,status,createtime,updatetime) VALUES (?,?,?,?,?,'normal',?,?)")->execute([$username,$brand,$companyId,password_hash('PortfolioDemo123!',PASSWORD_BCRYPT),'company_owner',$now,$now]);$userId=(int)$pdo->lastInsertId(); }
                $ctx=TenantContext::enterprise($userId,$companyId);$audit=new PdoAuditLogger($pdo,$prefix);$gateway=new TenantDataGateway($pdo,TenantResourceCatalog::registry($prefix),$audit);
                $profiles=new CompanyProfileService($pdo,$gateway,new TenantCompanyGateway($pdo,$prefix),new OpenSslContactCipher((string)Config::get('geo_company.contact_encryption_key')),$audit);
                $intro=$index===0?'虚构的家居收纳品牌，提供模块化收纳盒与桌面整理用品。所有产品资料仅用于 Portfolio 工作流演示。':'虚构的户外用品品牌，提供徒步收纳与轻量装备整理方案。所有资料仅用于隔离测试。';
                $profiles->save(['company_name'=>$name,'brand'=>$brand,'industry'=>$index===0?'家居用品':'户外用品','products_services'=>$index===0?['模块化收纳盒','桌面整理用品']:['徒步收纳袋','装备整理袋'],'website'=>'https://example.invalid','introduction'=>$intro,'email'=>'demo-'.$key.'@example.invalid'],$ctx);
                $content=(array)Config::get('geo_content');
                $knowledgeService=new KnowledgeItemService($pdo,$gateway,$audit,new PortfolioStorageAdapter('development',ROOT_PATH.'runtime/portfolio-storage'),new EnterpriseFileValidator(new ContentAssetPolicy($content['files'])),$content);
                $existing=$gateway->list('knowledge_items',$ctx,['title'=>'演示产品事实'],1);
                $knowledgeId=$existing===[]?(int)$knowledgeService->createText(['title'=>'演示产品事实','content'=>$intro.'建议根据空间尺寸、分类需求与使用频率选择规格；不提供未经证实的销量、功效或认证。'],$ctx)['id']:(int)$existing[0]['id'];
                $keywords=new KeywordQuestionService($pdo,$gateway,$audit);
                $existing=$gateway->list('keywords',$ctx,[],1);
                $keywordId=$existing===[]?(int)$keywords->createKeyword(['keyword'=>$index===0?'模块化收纳':'徒步收纳'],$ctx)['id']:(int)$existing[0]['id'];
                $questions=$gateway->list('questions',$ctx,[],100);
                if ($questions===[]) { (new QuestionGenerationService($pdo,$gateway,$audit))->generate(['keyword_id'=>$keywordId,'knowledge_item_ids'=>[$knowledgeId]],new PortfolioAiProvider(),$ctx);$questions=$gateway->list('questions',$ctx,[],100); }
                foreach ($questions as $q) { if ($q['status']==='pending_review') { (new QuestionGenerationService($pdo,$gateway,$audit))->review((int)$q['id'],true,$ctx); } }
                $questionId=(int)$questions[0]['id'];$answerService=new TargetAnswerService($pdo,$gateway,$audit);
                $existing=$gateway->list('geo_answer_versions',$ctx,['question_id'=>$questionId],1);
                $answer=$existing===[]?$answerService->generate($questionId,[$knowledgeId],new PortfolioAiProvider(),$ctx):$answerService->detail((int)$existing[0]['id'],$ctx);
                $answer=$answerService->confirm((int)$answer['id'],$ctx);
                $existing=$gateway->list('prompt_templates',$ctx,[],1);
                $promptId=$existing===[]?(int)(new PromptTemplateService($pdo,$gateway,$audit,$content))->create(['name'=>'事实约束的企业文章','scene'=>'article','content'=>'使用已确认资料回答用户问题，保留来源，不编造价格、资质、销量或效果。'],$ctx)['id']:(int)$existing[0]['id'];
                $billing=new BillingService($pdo,$audit,$prefix);$billing->ensureWallet($companyId);$billing->manualCredit($companyId,'1000.00',$userId,'Portfolio virtual balance; no real payment','portfolio-initial-credit');
                $out[$key]=['company_id'=>$companyId,'user_id'=>$userId,'keyword_id'=>$keywordId,'question_id'=>$questionId,'knowledge_id'=>$knowledgeId,'answer_id'=>(int)$answer['id'],'prompt_id'=>$promptId];
            }
            $pdo->exec("UPDATE fa_channels SET supplier_confirmed=1 WHERE code IN ('official_media','influencer')");
            $supplierId=(int)$pdo->query("SELECT id FROM fa_suppliers WHERE code='mock'")->fetchColumn();
            $channelIds=$pdo->query('SELECT code,id FROM fa_channels')->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ([['mock-media-official','示例新闻站（模拟）','official_media','/media/type/1'],['mock-media-influencer','示例内容号（模拟）','influencer','/media/type/3'],['mock-media-video','示例短视频号（模拟）','influencer','/video']] as $i=>$media) {
                $attributes=json_encode(['source_catalog'=>$media[3],'price_unconfirmed'=>false,'platform'=>'Portfolio Demo','region'=>'演示地区','remark'=>'虚构媒体，仅模拟发布','media_link'=>'http://127.0.0.1:8877/','market_price'=>10+$i*5],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $pdo->prepare("INSERT INTO fa_media_resources (supplier_id,external_id,channel_id,name,cost_price,cost_currency,status,attributes_json,last_synced_at,created_at,updated_at) VALUES (?,?,?,?,?,'CNY','active',?,NOW(),NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name)")->execute([$supplierId,$media[0],$channelIds[$media[2]],$media[1],10+$i*5,$attributes]);
            }
            $out['media_id']=(int)$pdo->query("SELECT id FROM fa_media_resources WHERE external_id='mock-media-official'")->fetchColumn();
            $pdo->prepare("UPDATE fa_portfolio_seed SET state='complete',resources_json=? WHERE id=1")->execute([json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            return $out;
        } finally { $pdo->query("SELECT RELEASE_LOCK('portfolio_seed')"); }
    }
}

<?php
require __DIR__ . '/bootstrap.php';
use app\common\portfolio\PortfolioAiProvider;
use app\common\ai\AiGenerationRequest;
check(class_exists(PortfolioAiProvider::class), 'Portfolio AI implemented');
$provider=new PortfolioAiProvider();
function output($provider,string $prompt,array $context): array {
    return json_decode($provider->generate(new AiGenerationRequest($prompt,json_encode($context),2048))->rawText(),true,64,JSON_THROW_ON_ERROR);
}
$q=output($provider,'Generate realistic user questions. Return strict JSON: {"questions":["..."]}.', ['keyword'=>['keyword'=>'示例收纳'],'profile'=>['brand'=>'示例甲']]);
check(count($q['questions'])>=2, 'question array generated');
$sources=[['source_type'=>'profile','source_id'=>'introduction','evidence_text'=>'示例甲提供模块化收纳用品。']];
$answer=output($provider,'Return only one JSON object with keys answer, recommendation_reason, and facts;', ['question'=>['text'=>'如何选型？'],'sources'=>$sources]);
check($answer['facts'][0]['evidence_quote']===$sources[0]['evidence_text'] && $answer['facts'][0]['source_id']==='introduction', 'target answer quotes actual supplied source');
$article=output($provider,'Return only a JSON object with exactly two string keys in this order: title, content.', ['company'=>['brand'=>'示例甲'],'question'=>['text'=>'如何选型？'],'geo_target_answer'=>$answer]);
check(array_keys($article)===['title','content'] && strlen($article['content'])>100,'article contract and useful body');
$suggestion=output($provider,'Return strict JSON arrays: knowledge_gaps, question_directions, answer_directions, content_directions.', ['question'=>'如何选型？']);
foreach (['knowledge_gaps','question_directions','answer_directions','content_directions'] as $key) { check(count($suggestion[$key])>0,'suggestion '.$key); }
check($q===output($provider,'Generate realistic user questions. Return strict JSON: {"questions":["..."]}.', ['keyword'=>['keyword'=>'示例收纳'],'profile'=>['brand'=>'示例甲']]), 'deterministic output');
rejects(fn()=>output($provider,'Unknown AI contract',[]),'unknown contract refused');
rejects(fn()=>output($provider,'Return only one JSON object with keys answer, recommendation_reason, and facts;', ['sources'=>[]]),'unsupported answer without sources refused');

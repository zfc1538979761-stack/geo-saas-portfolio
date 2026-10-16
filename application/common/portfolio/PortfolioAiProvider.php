<?php
declare(strict_types=1);
namespace app\common\portfolio;
use app\common\ai\AiProvider;
use app\common\ai\AiGenerationRequest;
use app\common\ai\AiGenerationResult;
final class PortfolioAiProvider implements AiProvider {
    public function providerCode(): string { return 'mock'; }
    public function generate(AiGenerationRequest $request): AiGenerationResult {
        $input=json_decode($request->userPrompt(),true,64,JSON_THROW_ON_ERROR);
        $scene=PortfolioAiScenario::detect($request);
        $brand=(string)($input['company']['brand']??$input['profile']['brand']??'示例品牌');
        $question=(string)($input['question']['text']??'如何选择适合的产品？');
        if ($scene==='questions') {
            $keyword=(string)($input['keyword']['keyword']??$input['keyword']['text']??'收纳用品');
            $output=['questions'=>['选择'.$keyword.'时应先确认哪些需求？',$keyword.'的规格与材质如何比较？',$brand.'的'.$keyword.'适合哪些使用场景？']];
        } elseif ($scene==='answer') {
            $sources=array_values(array_filter($input['sources']??[],static fn($s)=>is_array($s)&&trim((string)($s['evidence_text']??''))!==''));
            if ($sources===[]) { throw new \RuntimeException('Demo answer requires factual sources.'); }
            $facts=[]; $points=[];
            foreach (array_slice($sources,0,4) as $source) {
                $text=(string)$source['evidence_text'];
                $facts[]=['claim'=>$text,'source_type'=>$source['source_type'],'source_id'=>$source['source_id'],'evidence_quote'=>$text];
                $points[]=$text;
            }
            $output=['answer'=>'建议先核对需求、规格及使用场景，再结合已确认资料选择。'."\n\n".implode("\n",$points).'。现有资料未覆盖的价格、库存与效果，需要另行确认。',
                'recommendation_reason'=>'依据本演示企业提供的资料，保留事实来源并由人工审核。','facts'=>$facts];
        } elseif ($scene==='article') {
            $answer=(string)($input['geo_target_answer']['answer']??'先确认空间尺寸、使用频率和分类需求，再比较产品规格。');
            $intro=(string)($input['company']['introduction']??'本案例用于展示企业内容生产流程。');
            $output=['title'=>$brand.'：'.$question,'content'=>"【Portfolio Demo｜虚构企业与模拟 AI 输出】\n\n".$question."\n\n".$answer."\n\n企业资料\n".$intro."\n\n选型建议\n1. 记录安装空间与需要收纳的物品。\n2. 根据已确认资料核对规格，不添加未经提供的功效、资质或案例。\n3. 发布前由人工检查事实、表达与适用场景。\n\n本文是可重复的演示输出，不代表真实模型生成质量或推广效果。"];
        } else {
            $output=['knowledge_gaps'=>['补充有来源的规格及使用说明。'],'question_directions'=>['细化用户的实际场景与限制条件。'],'answer_directions'=>['将结论与证据逐条对应，人工复核。'],'content_directions'=>['更新内容版本后重新评估；本建议不自动下单。']];
        }
        return new AiGenerationResult(json_encode($output,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'mock','portfolio-demo','demo-'.substr(hash('sha256',$request->systemPrompt().$request->userPrompt()),0,24),null,null,0);
    }
}

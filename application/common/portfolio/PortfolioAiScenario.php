<?php
declare(strict_types=1);
namespace app\common\portfolio;
use app\common\ai\AiGenerationRequest;
final class PortfolioAiScenario {
    public static function detect(AiGenerationRequest $request): string {
        $prompt=$request->systemPrompt();
        if (strpos($prompt,'knowledge_gaps')!==false && strpos($prompt,'content_directions')!==false) { return 'suggestion'; }
        if (strpos($prompt,'recommendation_reason')!==false && strpos($prompt,'facts')!==false) { return 'answer'; }
        if (strpos($prompt,'"questions"')!==false) { return 'questions'; }
        if (strpos($prompt,'title, content')!==false) { return 'article'; }
        throw new \RuntimeException('Unsupported Portfolio AI contract.');
    }
}

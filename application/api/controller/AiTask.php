<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\ai\task\AiTaskException;
use app\common\ai\task\AiTaskRuntimeFactory;
use app\common\content\ContentException;
use app\common\foundation\ApiErrorCode;
use think\Request;
use think\Response;

final class AiTask extends T06ApiController
{
    public function create(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $input = $this->strictInput((array) $request->post(), ['keyword_id','question_id','prompt_template_id','knowledge_item_ids','article_id','current_version_id','geo_answer_version_id']);
            $input['knowledge_item_ids'] = $this->ids($input['knowledge_item_ids'] ?? []);
            return $this->task(fn() => AiTaskRuntimeFactory::service()->create(
                $input, (string) $request->header('Idempotency-Key', ''), $this->context($request), (string) $request->ip(0, false)
            ));
        });
    }

    public function detail(): Response { return $this->readAction('detail'); }
    public function result(): Response { return $this->readAction('result'); }
    public function retry(): Response { return $this->writeAction('retry'); }
    public function cancel(): Response { return $this->writeAction('cancel'); }

    private function readAction(string $method): Response
    {
        return $this->execute(function (Request $request) use ($method): array {
            $this->requireMethod($request, 'GET'); $query = $this->strictInput((array) $request->get(), ['id']);
            return $this->task(fn() => AiTaskRuntimeFactory::service()->{$method}(
                $this->integer($query['id'] ?? null), $this->context($request), (string) $request->ip(0, false)
            ));
        });
    }

    private function writeAction(string $method): Response
    {
        return $this->execute(function (Request $request) use ($method): array {
            $this->requireMethod($request, 'POST'); $post = $this->strictInput((array) $request->post(), ['id']);
            return $this->task(fn() => AiTaskRuntimeFactory::service()->{$method}(
                $this->integer($post['id'] ?? null), $this->context($request), (string) $request->ip(0, false)
            ));
        });
    }

    private function task(callable $operation): array
    {
        try { return $operation(); }
        catch (AiTaskException $exception) {
            $code = $exception->httpStatus() === 404 ? ApiErrorCode::NOT_FOUND : ApiErrorCode::INVALID_REQUEST;
            if ($exception->httpStatus() >= 500) { $code = ApiErrorCode::DEPENDENCY_UNAVAILABLE; }
            throw new ContentException($code, $exception->getMessage(), $exception->httpStatus());
        }
    }
}

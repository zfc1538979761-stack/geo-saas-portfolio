<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\content\KeywordQuestionServiceFactory;
use app\common\content\ContentAuthenticationException;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantContextFactory;
use app\common\tenant\TenantException;
use app\common\geo\GeoException;
use app\common\geo\GeoQuestionRuntimeFactory;
use app\common\company\intelligence\CompanyIntelligenceServiceFactory;
use app\common\content\ContentException;
use app\common\foundation\ApiErrorCode;
use think\Request;
use think\Response;

final class Question extends ContentApiController
{
    public function generate(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $posted = $this->strictInput((array) $request->post(), ['keyword_id', 'knowledge_item_ids']);
            $context = $this->context($request);
            $knowledgeIds = $posted['knowledge_item_ids'] ?? [];
            if (is_string($knowledgeIds)) { $knowledgeIds = json_decode($knowledgeIds, true); }
            if (!is_array($knowledgeIds) || $knowledgeIds === []) {
                $knowledgeId = CompanyIntelligenceServiceFactory::create()
                    ->latestVerifiedKnowledgeItemId($context);
                $knowledgeIds = $knowledgeId > 0 ? [$knowledgeId] : [];
            }
            try {
                return GeoQuestionRuntimeFactory::service()->generate([
                    'keyword_id' => $this->integer($posted['keyword_id'] ?? null),
                    'knowledge_item_ids' => $knowledgeIds,
                ], GeoQuestionRuntimeFactory::provider(), $context);
            } catch (GeoException $exception) {
                throw new ContentException(ApiErrorCode::INVALID_REQUEST, $exception->getMessage(), $exception->httpStatus());
            }
        });
    }

    public function review(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $posted = $this->strictInput((array) $request->post(), ['id', 'decision']);
            $decision = trim((string) ($posted['decision'] ?? ''));
            if (!in_array($decision, ['approved', 'rejected'], true)) {
                throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Review decision must be approved or rejected.', 422);
            }
            try {
                $question = GeoQuestionRuntimeFactory::service()->review(
                    $this->integer($posted['id'] ?? null),
                    $decision === 'approved',
                    $this->context($request)
                );
            } catch (GeoException $exception) {
                $code = $exception->httpStatus() === 404 ? ApiErrorCode::NOT_FOUND : ApiErrorCode::INVALID_REQUEST;
                throw new ContentException($code, $exception->getMessage(), $exception->httpStatus());
            }
            $question['review_status'] = $question['status'] === 'normal' ? 'approved' : 'rejected';
            return $question;
        });
    }

    public function index(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'GET');
            $query = (array) $request->get();
            $keywordId = $this->integer($query['keyword_id'] ?? null);
            unset($query['keyword_id']);
            return KeywordQuestionServiceFactory::create()->questionPage(
                $keywordId,
                $query,
                $this->context($request),
                (string) $request->ip(0, false)
            );
        });
    }

    public function detail(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'GET');
            $query = $this->strictInput((array) $request->get(), ['id']);
            return KeywordQuestionServiceFactory::create()->question(
                $this->integer($query['id'] ?? null),
                $this->context($request),
                (string) $request->ip(0, false)
            );
        });
    }

    public function create(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $posted = $this->strictInput((array) $request->post(), ['keyword_id', 'text']);
            return KeywordQuestionServiceFactory::create()->createQuestion(
                $this->integer($posted['keyword_id'] ?? null),
                ['text' => $posted['text'] ?? null],
                $this->context($request),
                (string) $request->ip(0, false)
            );
        });
    }

    public function update(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $posted = $this->strictInput((array) $request->post(), ['id', 'text']);
            return KeywordQuestionServiceFactory::create()->updateQuestion(
                $this->integer($posted['id'] ?? null),
                ['text' => $posted['text'] ?? null],
                $this->context($request),
                (string) $request->ip(0, false)
            );
        });
    }

    public function status(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $posted = $this->strictInput((array) $request->post(), ['id', 'status']);
            return KeywordQuestionServiceFactory::create()->setQuestionStatus(
                $this->integer($posted['id'] ?? null),
                (string) ($posted['status'] ?? ''),
                $this->context($request),
                (string) $request->ip(0, false)
            );
        });
    }

    public function batch(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $posted = $this->strictInput((array) $request->post(), ['ids', 'status']);
            $ids = $posted['ids'] ?? [];
            if (is_string($ids)) {
                $ids = json_decode($ids, true);
            }
            return KeywordQuestionServiceFactory::create()->batchQuestionStatus(
                is_array($ids) ? $ids : [],
                (string) ($posted['status'] ?? ''),
                $this->context($request),
                (string) $request->ip(0, false)
            );
        });
    }

    public function delete(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $posted = $this->strictInput((array) $request->post(), ['id']);
            KeywordQuestionServiceFactory::create()->deleteQuestion(
                $this->integer($posted['id'] ?? null),
                $this->context($request),
                (string) $request->ip(0, false)
            );
            return ['deleted' => true];
        });
    }

    private function context(Request $request): TenantContext
    {
        try {
            return TenantContextFactory::fromEnterpriseToken($this->bearerToken($request));
        } catch (ContentAuthenticationException $exception) {
            throw $exception;
        } catch (TenantException $exception) {
            throw new ContentAuthenticationException('Unauthenticated tenant context.');
        }
    }
}

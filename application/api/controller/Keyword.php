<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\content\KeywordQuestionServiceFactory;
use app\common\content\ContentAuthenticationException;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantContextFactory;
use app\common\tenant\TenantException;
use think\Request;
use think\Response;

final class Keyword extends ContentApiController
{
    public function index(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'GET');
            return KeywordQuestionServiceFactory::create()->keywordPage(
                (array) $request->get(),
                $this->context($request)
            );
        });
    }

    public function detail(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'GET');
            $query = $this->strictInput((array) $request->get(), ['id']);
            return KeywordQuestionServiceFactory::create()->keyword(
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
            return KeywordQuestionServiceFactory::create()->createKeyword(
                $this->strictInput((array) $request->post(), ['keyword']),
                $this->context($request),
                (string) $request->ip(0, false)
            );
        });
    }

    public function update(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $posted = $this->strictInput((array) $request->post(), ['id', 'keyword']);
            return KeywordQuestionServiceFactory::create()->updateKeyword(
                $this->integer($posted['id'] ?? null),
                ['keyword' => $posted['keyword'] ?? null],
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
            return KeywordQuestionServiceFactory::create()->setKeywordStatus(
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
            return KeywordQuestionServiceFactory::create()->batchKeywordStatus(
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
            KeywordQuestionServiceFactory::create()->deleteKeyword(
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

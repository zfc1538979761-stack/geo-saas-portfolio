<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\company\intelligence\CompanyIntelligenceServiceFactory;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantContextFactory;
use app\common\tenant\TenantException;
use app\common\content\ContentAuthenticationException;
use think\Request;
use think\Response;

final class CompanyIntelligence extends ContentApiController
{
    public function discover(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $input = $this->strictInput((array) $request->post(), [
                'company_name', 'region', 'website', 'unified_social_credit_code',
            ]);
            return CompanyIntelligenceServiceFactory::create()->discover(
                $input,
                CompanyIntelligenceServiceFactory::discoveryProvider(),
                $this->context($request),
                (string) $request->ip(0, false)
            );
        });
    }

    public function candidates(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'GET');
            $query = $this->strictInput((array) $request->get(), ['run_id']);
            return CompanyIntelligenceServiceFactory::create()->candidates(
                $this->integer($query['run_id'] ?? null),
                $this->context($request)
            );
        });
    }

    public function confirmCandidate(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $input = $this->strictInput((array) $request->post(), ['candidate_id']);
            return CompanyIntelligenceServiceFactory::create()->confirmCandidate(
                $this->integer($input['candidate_id'] ?? null),
                CompanyIntelligenceServiceFactory::aiProvider(),
                $this->context($request),
                (string) $request->ip(0, false)
            );
        });
    }

    public function draft(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'GET');
            $query = $this->strictInput((array) $request->get(), ['id']);
            return CompanyIntelligenceServiceFactory::create()->draft(
                $this->integer($query['id'] ?? null),
                $this->context($request)
            );
        });
    }

    public function confirmDraft(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $input = $this->strictInput((array) $request->post(), ['draft_id', 'profile', 'resolutions']);
            return CompanyIntelligenceServiceFactory::create()->confirmDraft(
                $this->integer($input['draft_id'] ?? null),
                $this->object($input['profile'] ?? []),
                $this->object($input['resolutions'] ?? []),
                $this->context($request),
                (string) $request->ip(0, false)
            );
        });
    }

    public function status(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'GET');
            $this->strictInput((array) $request->get(), []);
            return CompanyIntelligenceServiceFactory::create()->status($this->context($request));
        });
    }

    private function object($value): array
    {
        if (is_string($value)) { $value = json_decode($value, true); }
        if (!is_array($value)) {
            throw new \app\common\company\intelligence\CompanyDiscoveryException(
                'invalid_request', '画像或冲突选择格式无效。', 422
            );
        }
        return $value;
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

<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\geo\GeoVisibilityRuntimeFactory;
use think\Request;
use think\Response;

final class GeoVisibility extends GeoApiController
{
    public function baseline(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $input = $this->strictInput((array) $request->post(), ['question_id', 'platform', 'model_name']);
            $input['check_type'] = 'baseline';
            $input['idempotency_key'] = 'enterprise-baseline';
            return $this->geo(fn(): array => GeoVisibilityRuntimeFactory::service()->check(
                $input,
                GeoVisibilityRuntimeFactory::provider(),
                $this->context($request)
            ));
        });
    }

    public function result(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'GET');
            $query = $this->strictInput((array) $request->get(), ['id']);
            return $this->geo(fn(): array => GeoVisibilityRuntimeFactory::service()->result(
                $this->integer($query['id'] ?? null),
                $this->context($request)
            ));
        });
    }

    public function history(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'GET');
            $query = $this->strictInput((array) $request->get(), ['question_id', 'platform', 'model_name', 'page', 'per_page']);
            $filters = ['question_id' => $this->integer($query['question_id'] ?? null)];
            foreach (['platform', 'model_name'] as $field) {
                if (isset($query[$field]) && trim((string) $query[$field]) !== '') { $filters[$field] = trim((string) $query[$field]); }
            }
            return $this->geo(fn(): array => GeoVisibilityRuntimeFactory::service()->history(
                $filters,
                $this->page($query['page'] ?? null),
                $this->perPage($query['per_page'] ?? null),
                $this->context($request)
            ));
        });
    }

    public function create_plan(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $input = $this->strictInput((array) $request->post(), [
                'question_id', 'baseline_result_id', 'platform', 'model_name',
                'interval_days', 'timezone', 'next_run_at',
            ]);
            $input['provider_code'] = GeoVisibilityRuntimeFactory::provider()->providerCode();
            return $this->geo(fn(): array => GeoVisibilityRuntimeFactory::scheduler()->createPlan(
                $input,
                $this->context($request)
            ));
        });
    }

    public function createplan(): Response
    {
        return $this->create_plan();
    }

    public function update_plan(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $input = $this->strictInput((array) $request->post(), ['id', 'interval_days', 'enabled', 'next_run_at']);
            $id = $this->integer($input['id'] ?? null);
            unset($input['id']);
            return $this->geo(fn(): array => GeoVisibilityRuntimeFactory::scheduler()->updatePlan(
                $id,
                $input,
                $this->context($request)
            ));
        });
    }

    public function updateplan(): Response
    {
        return $this->update_plan();
    }

    public function plans(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'GET');
            $query = $this->strictInput((array) $request->get(), ['question_id', 'page', 'per_page']);
            $questionId = isset($query['question_id']) && $query['question_id'] !== ''
                ? $this->integer($query['question_id'])
                : null;
            return $this->geo(fn(): array => GeoVisibilityRuntimeFactory::scheduler()->plans(
                $questionId,
                $this->page($query['page'] ?? null),
                $this->perPage($query['per_page'] ?? null),
                $this->context($request)
            ));
        });
    }

    public function trend(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $input = $this->strictInput((array) $request->post(), ['current_result_id']);
            return $this->geo(fn(): array => GeoVisibilityRuntimeFactory::trend()->compare(
                $this->integer($input['current_result_id'] ?? null),
                $this->context($request)
            ));
        });
    }

    public function suggestion(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $input = $this->strictInput((array) $request->post(), ['result_id']);
            return $this->geo(fn(): array => GeoVisibilityRuntimeFactory::suggestions()->generate(
                $this->integer($input['result_id'] ?? null),
                GeoVisibilityRuntimeFactory::optimizationProvider(),
                $this->context($request)
            ));
        });
    }
}

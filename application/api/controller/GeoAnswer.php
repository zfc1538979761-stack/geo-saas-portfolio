<?php

declare(strict_types=1);

namespace app\api\controller;

use app\common\content\ContentException;
use app\common\foundation\ApiErrorCode;
use app\common\geo\GeoAnswerRuntimeFactory;
use think\Request;
use think\Response;

final class GeoAnswer extends GeoApiController
{
    public function generate(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $input = $this->strictInput((array) $request->post(), ['question_id', 'knowledge_item_ids']);
            return $this->geo(fn(): array => GeoAnswerRuntimeFactory::service()->generate(
                $this->integer($input['question_id'] ?? null),
                $this->ids($input['knowledge_item_ids'] ?? []),
                GeoAnswerRuntimeFactory::provider(),
                $this->context($request)
            ));
        });
    }

    public function detail(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'GET');
            $query = $this->strictInput((array) $request->get(), ['id']);
            return $this->geo(fn(): array => GeoAnswerRuntimeFactory::service()->detail(
                $this->integer($query['id'] ?? null),
                $this->context($request)
            ));
        });
    }

    public function history(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'GET');
            $query = $this->strictInput((array) $request->get(), ['question_id', 'page', 'per_page']);
            return $this->geo(fn(): array => GeoAnswerRuntimeFactory::service()->versions(
                $this->integer($query['question_id'] ?? null),
                $this->page($query['page'] ?? null),
                $this->perPage($query['per_page'] ?? null),
                $this->context($request)
            ));
        });
    }

    public function revise(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $input = $this->strictInput((array) $request->post(), ['id', 'answer', 'recommendation_reason', 'facts']);
            $id = $this->integer($input['id'] ?? null);
            unset($input['id']);
            $input['facts'] = $this->objectList($input['facts'] ?? []);
            return $this->geo(fn(): array => GeoAnswerRuntimeFactory::service()->revise(
                $id,
                $input,
                $this->context($request)
            ));
        });
    }

    public function confirm(): Response
    {
        return $this->execute(function (Request $request): array {
            $this->requireMethod($request, 'POST');
            $input = $this->strictInput((array) $request->post(), ['id']);
            return $this->geo(fn(): array => GeoAnswerRuntimeFactory::service()->confirm(
                $this->integer($input['id'] ?? null),
                $this->context($request)
            ));
        });
    }

    private function objectList($value): array
    {
        if (is_string($value)) { $value = json_decode($value, true); }
        if (!is_array($value) || $value === []) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'GEO answer facts are invalid.', 422);
        }
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'GEO answer facts are invalid.', 422);
            }
        }
        return array_values($value);
    }
}

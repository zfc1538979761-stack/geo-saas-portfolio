<?php

declare(strict_types=1);

namespace app\common\content;

use app\common\foundation\ApiErrorCode;
use app\common\tenant\AuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use PDO;
use Throwable;

abstract class AbstractContentService
{
    protected $pdo;
    protected $gateway;
    protected $auditLogger;
    protected $config;
    private $clock;

    public function __construct(
        PDO $pdo,
        TenantDataGateway $gateway,
        AuditLogger $auditLogger,
        array $config,
        ?callable $clock = null
    ) {
        $this->pdo = $pdo;
        $this->gateway = $gateway;
        $this->auditLogger = $auditLogger;
        $this->config = $config;
        $this->clock = $clock ?: static function (): int { return time(); };
    }

    protected function transaction(callable $operation)
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) { $this->pdo->beginTransaction(); }
        try {
            $result = $operation();
            if ($owns) { $this->pdo->commit(); }
            return $result;
        } catch (Throwable $exception) {
            if ($owns && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    protected function fields(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Request contains protected or unknown fields.', 422);
        }
    }

    protected function text($value, string $name, int $maximum): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, $name . ' is invalid.', 422);
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maximum) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, $name . ' is outside the allowed length.', 422);
        }
        return $value;
    }

    protected function id($value, string $name = 'Resource ID'): int
    {
        if (!(is_int($value) || (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) || (int) $value < 1) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, $name . ' is invalid.', 422);
        }
        return (int) $value;
    }

    protected function ids($value, string $name = 'Resource IDs'): array
    {
        if (!is_array($value) || count($value) > 100) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, $name . ' are invalid.', 422);
        }
        $ids = [];
        foreach ($value as $id) { $ids[] = $this->id($id, $name); }
        return array_values(array_unique($ids));
    }

    protected function paginationInput(array $query, array $sorts, string $defaultSort = 'updatetime'): array
    {
        $allowed = ['page', 'per_page', 'search', 'status', 'type', 'scene', 'sort', 'direction'];
        $this->fields($query, $allowed);
        $pagination = (array) ($this->config['pagination'] ?? []);
        $page = $this->id($query['page'] ?? 1, 'Page');
        $perPage = $this->id($query['per_page'] ?? ($pagination['default_per_page'] ?? 20), 'Page size');
        if ($page > (int) ($pagination['maximum_page'] ?? 100000) || $perPage > (int) ($pagination['maximum_per_page'] ?? 100)) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Pagination exceeds the allowed limit.', 422);
        }
        $search = isset($query['search']) ? $this->optionalText($query['search'], 'Search', (int) ($this->config['text']['search_max_characters'] ?? 200)) : '';
        $sort = (string) ($query['sort'] ?? $defaultSort);
        if (!in_array($sort, $sorts, true)) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Sort field is invalid.', 422);
        }
        $direction = strtolower((string) ($query['direction'] ?? 'desc'));
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Sort direction is invalid.', 422);
        }
        return [$page, $perPage, $search, $sort, $direction];
    }

    protected function optionalText($value, string $name, int $maximum): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1 || mb_strlen($value, 'UTF-8') > $maximum) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, $name . ' is invalid.', 422);
        }
        return trim($value);
    }

    protected function locked(string $resource, int $id, TenantContext $context, string $ip = '', array $filters = []): array
    {
        $filters = array_merge(['deletetime' => null], $filters);
        $rows = $this->gateway->findMany($resource, [$id], $context, $filters, true, $ip);
        if (!is_array($rows)) { $this->notFound(); }
        return $rows[0];
    }

    protected function visible(string $resource, int $id, TenantContext $context, string $ip = ''): array
    {
        $row = $this->gateway->find($resource, $id, $context, $ip);
        if (!is_array($row) || $row['deletetime'] !== null) { $this->notFound(); }
        return $row;
    }

    protected function notFound(): void
    {
        throw new ContentException(ApiErrorCode::NOT_FOUND, 'Resource not found.', 404);
    }

    protected function conflict(string $message): void
    {
        throw new ContentException(ApiErrorCode::INVALID_REQUEST, $message, 409);
    }

    protected function audit(string $action, string $type, ?int $id, TenantContext $context, string $ip = '', array $detail = []): void
    {
        $this->auditLogger->record($action, $context, $type, $id === null ? null : (string) $id, $ip, $detail);
    }

    protected function now(): int
    {
        return (int) call_user_func($this->clock);
    }

    protected function companyId(TenantContext $context): int
    {
        $id = $context->companyId();
        if ($id === null || $id < 1 || $context->canBypass()) { $this->notFound(); }
        return $id;
    }
}

<?php

declare(strict_types=1);

namespace app\common\content;

use app\common\foundation\ApiErrorCode;
use app\common\tenant\AuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use PDO;
use PDOException;
use Throwable;

final class KeywordQuestionService
{
    private $pdo;
    private $gateway;
    private $audit;
    private $normalizer;
    private $clock;

    public function __construct(
        PDO $pdo,
        TenantDataGateway $gateway,
        AuditLogger $audit,
        ?ContentTextNormalizer $normalizer = null,
        ?callable $clock = null
    ) {
        $this->pdo = $pdo;
        $this->gateway = $gateway;
        $this->audit = $audit;
        $this->normalizer = $normalizer ?: new ContentTextNormalizer();
        $this->clock = $clock ?: static function (): int {
            return time();
        };
    }

    public function createKeyword(array $input, TenantContext $context, string $ip = ''): array
    {
        $this->fields($input, ['keyword']);
        $keyword = $this->normalizer->keyword($input['keyword'] ?? null);
        return $this->transaction(function () use ($keyword, $context, $ip): array {
            $now = $this->now();
            try {
                $id = $this->gateway->insert('keywords', [
                    'keyword' => $keyword,
                    'status' => ContentRules::STATUS_NORMAL,
                    'created_by' => $context->actorId(),
                    'updated_by' => $context->actorId(),
                    'createtime' => $now,
                    'updatetime' => $now,
                    'deletetime' => null,
                ], $context);
            } catch (PDOException $exception) {
                $this->duplicate($exception, 'Keyword already exists.');
                throw $exception;
            }
            $this->audit('keyword_created', 'keyword', $id, $context, $ip);
            return $this->keyword($id, $context, $ip);
        });
    }

    public function updateKeyword(int $id, array $input, TenantContext $context, string $ip = ''): array
    {
        $this->positiveId($id);
        $this->fields($input, ['keyword']);
        $keyword = $this->normalizer->keyword($input['keyword'] ?? null);
        return $this->transaction(function () use ($id, $keyword, $context, $ip): array {
            $this->locked('keywords', $id, $context, $ip);
            try {
                $updated = $this->gateway->update('keywords', $id, [
                    'keyword' => $keyword,
                    'updated_by' => $context->actorId(),
                    'updatetime' => $this->now(),
                ], $context, $ip);
            } catch (PDOException $exception) {
                $this->duplicate($exception, 'Keyword already exists.');
                throw $exception;
            }
            $this->found($updated);
            $this->audit('keyword_updated', 'keyword', $id, $context, $ip);
            return $this->keyword($id, $context, $ip);
        });
    }

    public function keyword(int $id, TenantContext $context, string $ip = ''): array
    {
        $this->positiveId($id);
        $row = $this->gateway->find('keywords', $id, $context, $ip);
        if (!is_array($row) || $row['deletetime'] !== null) {
            $this->notFound();
        }
        return $this->presentKeyword($row);
    }

    public function keywordPage(array $query, TenantContext $context): array
    {
        $this->fields($query, ['page', 'per_page', 'search', 'status', 'sort', 'direction']);
        [$page, $perPage] = $this->page($query);
        $search = $this->normalizer->search($query['search'] ?? '');
        $status = $this->normalizer->optionalStatus($query['status'] ?? null);
        $sort = $this->sort(
            (string) ($query['sort'] ?? 'createtime'),
            ['id', 'keyword', 'status', 'createtime', 'updatetime']
        );
        $direction = $this->direction((string) ($query['direction'] ?? 'desc'));
        $filters = ['deletetime' => null];
        if ($status !== null) {
            $filters['status'] = $status;
        }
        $result = $this->gateway->paginate(
            'keywords', $context, $filters, 'keyword', $search, $page, $perPage, $sort, $direction
        );
        $result['items'] = array_map([$this, 'presentKeyword'], $result['items']);
        return $result;
    }

    public function setKeywordStatus(
        int $id,
        string $status,
        TenantContext $context,
        string $ip = ''
    ): array {
        $this->positiveId($id);
        $status = $this->normalizer->status($status);
        return $this->transaction(function () use ($id, $status, $context, $ip): array {
            $this->locked('keywords', $id, $context, $ip);
            $this->found($this->gateway->update('keywords', $id, [
                'status' => $status,
                'updated_by' => $context->actorId(),
                'updatetime' => $this->now(),
            ], $context, $ip));
            $this->audit('keyword_status_changed', 'keyword', $id, $context, $ip, ['status' => $status]);
            return $this->keyword($id, $context, $ip);
        });
    }

    public function deleteKeyword(int $id, TenantContext $context, string $ip = ''): void
    {
        $this->positiveId($id);
        $this->transaction(function () use ($id, $context, $ip): void {
            $this->locked('keywords', $id, $context, $ip);
            if ($this->gateway->count('questions', $context, ['keyword_id' => $id, 'deletetime' => null]) > 0) {
                throw new ContentException(
                    ApiErrorCode::INVALID_REQUEST,
                    'Delete active questions before deleting the keyword.',
                    409
                );
            }
            $this->found($this->gateway->update('keywords', $id, [
                'status' => ContentRules::STATUS_DISABLED,
                'updated_by' => $context->actorId(),
                'updatetime' => $this->now(),
                'deletetime' => $this->now(),
            ], $context, $ip));
            $this->audit('keyword_deleted', 'keyword', $id, $context, $ip);
        });
    }

    public function batchKeywordStatus(
        array $ids,
        string $status,
        TenantContext $context,
        string $ip = ''
    ): array {
        $ids = $this->ids($ids);
        $status = $this->normalizer->status($status);
        return $this->transaction(function () use ($ids, $status, $context, $ip): array {
            $this->found($this->gateway->updateMany('keywords', $ids, [
                'status' => $status,
                'updated_by' => $context->actorId(),
                'updatetime' => $this->now(),
            ], $context, $ip, ['deletetime' => null]));
            $this->audit('keyword_batch_status_changed', 'keyword', null, $context, $ip, [
                'status' => $status,
                'target_ids' => $ids,
            ]);
            return ['ids' => $ids, 'status' => $status];
        });
    }

    public function createQuestion(
        int $keywordId,
        array $input,
        TenantContext $context,
        string $ip = ''
    ): array {
        $this->positiveId($keywordId);
        $this->fields($input, ['text']);
        $text = $this->normalizer->question($input['text'] ?? null);
        return $this->transaction(function () use ($keywordId, $text, $context, $ip): array {
            $keyword = $this->locked('keywords', $keywordId, $context, $ip);
            if ($keyword['status'] !== ContentRules::STATUS_NORMAL) {
                throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Keyword is disabled.', 409);
            }
            $now = $this->now();
            try {
                $id = $this->gateway->insert('questions', [
                    'keyword_id' => $keywordId,
                    'text' => $text,
                    'text_hash' => $this->normalizer->questionDigest($text),
                    'source' => ContentRules::QUESTION_SOURCE_MANUAL,
                    'status' => ContentRules::STATUS_NORMAL,
                    'created_by' => $context->actorId(),
                    'updated_by' => $context->actorId(),
                    'createtime' => $now,
                    'updatetime' => $now,
                    'deletetime' => null,
                ], $context);
            } catch (PDOException $exception) {
                $this->duplicate($exception, 'Question already exists for this keyword.');
                throw $exception;
            }
            $this->audit('question_created', 'question', $id, $context, $ip, ['keyword_id' => $keywordId]);
            return $this->question($id, $context, $ip);
        });
    }

    public function updateQuestion(int $id, array $input, TenantContext $context, string $ip = ''): array
    {
        $this->positiveId($id);
        $this->fields($input, ['text']);
        $text = $this->normalizer->question($input['text'] ?? null);
        return $this->transaction(function () use ($id, $text, $context, $ip): array {
            $row = $this->locked('questions', $id, $context, $ip);
            $this->locked('keywords', (int) $row['keyword_id'], $context, $ip);
            try {
                $updated = $this->gateway->update('questions', $id, [
                    'text' => $text,
                    'text_hash' => $this->normalizer->questionDigest($text),
                    'updated_by' => $context->actorId(),
                    'updatetime' => $this->now(),
                ], $context, $ip);
            } catch (PDOException $exception) {
                $this->duplicate($exception, 'Question already exists for this keyword.');
                throw $exception;
            }
            $this->found($updated);
            $this->audit('question_updated', 'question', $id, $context, $ip, [
                'keyword_id' => (int) $row['keyword_id'],
            ]);
            return $this->question($id, $context, $ip);
        });
    }

    public function question(int $id, TenantContext $context, string $ip = ''): array
    {
        $this->positiveId($id);
        $row = $this->gateway->find('questions', $id, $context, $ip);
        if (!is_array($row) || $row['deletetime'] !== null) {
            $this->notFound();
        }
        $keyword = $this->gateway->find('keywords', (int) $row['keyword_id'], $context, $ip);
        if (!is_array($keyword) || $keyword['deletetime'] !== null) {
            $this->notFound();
        }
        return $this->presentQuestion($row, $keyword);
    }

    public function questionPage(int $keywordId, array $query, TenantContext $context, string $ip = ''): array
    {
        $this->positiveId($keywordId);
        $this->fields($query, ['page', 'per_page', 'search', 'status', 'sort', 'direction']);
        $keyword = $this->keyword($keywordId, $context, $ip);
        [$page, $perPage] = $this->page($query);
        $search = $this->normalizer->search($query['search'] ?? '');
        $status = $this->normalizer->optionalStatus($query['status'] ?? null);
        $sort = $this->sort(
            (string) ($query['sort'] ?? 'createtime'),
            ['id', 'text', 'status', 'createtime', 'updatetime']
        );
        $direction = $this->direction((string) ($query['direction'] ?? 'desc'));
        $filters = ['keyword_id' => $keywordId, 'deletetime' => null];
        if ($status !== null) {
            $filters['status'] = $status;
        }
        $result = $this->gateway->paginate(
            'questions', $context, $filters, 'text', $search, $page, $perPage, $sort, $direction
        );
        $rawKeyword = ['id' => $keyword['id'], 'keyword' => $keyword['keyword'], 'status' => $keyword['status']];
        $result['items'] = array_map(function (array $row) use ($rawKeyword): array {
            return $this->presentQuestion($row, $rawKeyword);
        }, $result['items']);
        $result['keyword'] = $rawKeyword;
        return $result;
    }

    public function setQuestionStatus(
        int $id,
        string $status,
        TenantContext $context,
        string $ip = ''
    ): array {
        $this->positiveId($id);
        $status = $this->normalizer->status($status);
        return $this->transaction(function () use ($id, $status, $context, $ip): array {
            $question = $this->locked('questions', $id, $context, $ip);
            if ($status === ContentRules::STATUS_NORMAL) {
                $keyword = $this->locked('keywords', (int) $question['keyword_id'], $context, $ip);
                if ($keyword['status'] !== ContentRules::STATUS_NORMAL) {
                    throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Keyword is disabled.', 409);
                }
            }
            $this->found($this->gateway->update('questions', $id, [
                'status' => $status,
                'updated_by' => $context->actorId(),
                'updatetime' => $this->now(),
            ], $context, $ip));
            $this->audit('question_status_changed', 'question', $id, $context, $ip, ['status' => $status]);
            return $this->question($id, $context, $ip);
        });
    }

    public function deleteQuestion(int $id, TenantContext $context, string $ip = ''): void
    {
        $this->positiveId($id);
        $this->transaction(function () use ($id, $context, $ip): void {
            $this->locked('questions', $id, $context, $ip);
            $this->found($this->gateway->update('questions', $id, [
                'status' => ContentRules::STATUS_DISABLED,
                'updated_by' => $context->actorId(),
                'updatetime' => $this->now(),
                'deletetime' => $this->now(),
            ], $context, $ip));
            $this->audit('question_deleted', 'question', $id, $context, $ip);
        });
    }

    public function batchQuestionStatus(
        array $ids,
        string $status,
        TenantContext $context,
        string $ip = ''
    ): array {
        $ids = $this->ids($ids);
        $status = $this->normalizer->status($status);
        return $this->transaction(function () use ($ids, $status, $context, $ip): array {
            $questions = $this->gateway->findMany(
                'questions', $ids, $context, ['deletetime' => null], true, $ip
            );
            $this->found(is_array($questions));
            if ($status === ContentRules::STATUS_NORMAL) {
                $keywordIds = array_values(array_unique(array_map(static function (array $row): int {
                    return (int) $row['keyword_id'];
                }, $questions)));
                $keywords = $this->gateway->findMany(
                    'keywords', $keywordIds, $context,
                    ['deletetime' => null, 'status' => ContentRules::STATUS_NORMAL], true, $ip
                );
                if (!is_array($keywords)) {
                    throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'A keyword is disabled.', 409);
                }
            }
            $this->found($this->gateway->updateMany('questions', $ids, [
                'status' => $status,
                'updated_by' => $context->actorId(),
                'updatetime' => $this->now(),
            ], $context, $ip, ['deletetime' => null]));
            $this->audit('question_batch_status_changed', 'question', null, $context, $ip, [
                'status' => $status,
                'target_ids' => $ids,
            ]);
            return ['ids' => $ids, 'status' => $status];
        });
    }

    public function activeQuestions(int $keywordId, TenantContext $context, string $ip = ''): array
    {
        $keyword = $this->keyword($keywordId, $context, $ip);
        if ($keyword['status'] !== ContentRules::STATUS_NORMAL) {
            return [];
        }
        $rows = [];
        $page = 1;
        do {
            $result = $this->gateway->paginate(
                'questions', $context,
                ['keyword_id' => $keywordId, 'status' => ContentRules::STATUS_NORMAL, 'deletetime' => null],
                null, '', $page, ContentRules::MAX_PAGE_SIZE, 'id', 'asc'
            );
            $rows = array_merge($rows, $result['items']);
            $page++;
        } while (count($rows) < $result['total']);
        return array_map(function (array $row) use ($keyword): array {
            return $this->presentQuestion($row, $keyword);
        }, $rows);
    }

    private function locked(string $resource, int $id, TenantContext $context, string $ip): array
    {
        $rows = $this->gateway->findMany($resource, [$id], $context, ['deletetime' => null], true, $ip);
        if (!is_array($rows)) {
            $this->notFound();
        }
        return $rows[0];
    }

    private function transaction(callable $operation)
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $operation();
            if ($owns) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function fields(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Request contains protected or unknown fields.', 422);
        }
    }

    private function ids(array $ids): array
    {
        if ($ids === [] || count($ids) > ContentRules::MAX_BATCH_IDS) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Batch IDs are invalid.', 422);
        }
        $normalized = [];
        foreach ($ids as $id) {
            if (!(is_int($id) || (is_string($id) && ctype_digit($id))) || (int) $id < 1) {
                throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Batch IDs are invalid.', 422);
            }
            $normalized[] = (int) $id;
        }
        return array_values(array_unique($normalized));
    }

    private function page(array $query): array
    {
        $page = $this->unsigned($query['page'] ?? 1, 'page');
        $perPage = $this->unsigned($query['per_page'] ?? ContentRules::DEFAULT_PAGE_SIZE, 'per_page');
        if ($perPage > ContentRules::MAX_PAGE_SIZE) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Page size exceeds the limit.', 422);
        }
        if ($page > ContentRules::MAX_PAGE) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Page exceeds the supported range.', 422);
        }
        return [$page, $perPage];
    }

    private function unsigned($value, string $name): int
    {
        if (!(is_int($value) || (is_string($value) && ctype_digit($value))) || (int) $value < 1) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, ucfirst($name) . ' is invalid.', 422);
        }
        return (int) $value;
    }

    private function sort(string $sort, array $allowed): string
    {
        if (!in_array($sort, $allowed, true)) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Sort field is invalid.', 422);
        }
        return $sort;
    }

    private function direction(string $direction): string
    {
        $direction = strtolower($direction);
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Sort direction is invalid.', 422);
        }
        return $direction;
    }

    private function positiveId(int $id): void
    {
        if ($id < 1) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Resource ID is invalid.', 422);
        }
    }

    private function found(bool $found): void
    {
        if (!$found) {
            $this->notFound();
        }
    }

    private function notFound(): void
    {
        throw new ContentException(ApiErrorCode::NOT_FOUND, 'Resource not found.', 404);
    }

    private function duplicate(PDOException $exception, string $message): void
    {
        if ($exception->getCode() === '23000' && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
            throw new ContentException(ApiErrorCode::INVALID_REQUEST, $message, 409);
        }
    }

    private function audit(
        string $action,
        string $type,
        ?int $id,
        TenantContext $context,
        string $ip,
        array $detail = []
    ): void {
        $this->audit->record($action, $context, $type, $id === null ? null : (string) $id, $ip, $detail);
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }

    private function presentKeyword(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'keyword' => (string) $row['keyword'],
            'status' => (string) $row['status'],
            'created_by' => (int) $row['created_by'],
            'updated_by' => (int) $row['updated_by'],
            'created_at' => (int) $row['createtime'],
            'updated_at' => (int) $row['updatetime'],
        ];
    }

    private function presentQuestion(array $row, array $keyword): array
    {
        return [
            'id' => (int) $row['id'],
            'keyword_id' => (int) $row['keyword_id'],
            'keyword' => [
                'id' => (int) $keyword['id'],
                'keyword' => (string) $keyword['keyword'],
                'status' => (string) $keyword['status'],
            ],
            'text' => (string) $row['text'],
            'source' => (string) $row['source'],
            'status' => (string) $row['status'],
            'created_by' => (int) $row['created_by'],
            'updated_by' => (int) $row['updated_by'],
            'created_at' => (int) $row['createtime'],
            'updated_at' => (int) $row['updatetime'],
        ];
    }
}

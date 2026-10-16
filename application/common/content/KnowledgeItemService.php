<?php

declare(strict_types=1);

namespace app\common\content;

use app\common\content\foundation\ContentVersionRules;
use app\common\content\foundation\EnterpriseFileValidator;
use app\common\content\foundation\SafePreview;
use app\common\content\storage\StorageAdapter;
use app\common\content\storage\StorageException;
use app\common\content\storage\StorageReadResult;
use app\common\content\storage\StoredObject;
use app\common\foundation\ApiErrorCode;
use app\common\tenant\AuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

final class KnowledgeItemService extends AbstractContentService
{
    private $storage;
    private $validator;

    public function __construct(PDO $pdo, TenantDataGateway $gateway, AuditLogger $audit, StorageAdapter $storage, EnterpriseFileValidator $validator, array $config, ?callable $clock = null)
    {
        parent::__construct($pdo, $gateway, $audit, $config, $clock);
        $this->storage = $storage;
        $this->validator = $validator;
    }

    public function createText(array $input, TenantContext $context, string $ip = ''): array
    {
        $this->fields($input, ['title', 'content']);
        $title = $this->title($input['title'] ?? null);
        $content = $this->text($input['content'] ?? null, 'Knowledge content', (int) $this->config['text']['knowledge_max_characters']);
        return $this->transaction(function () use ($title, $content, $context, $ip): array {
            $now = $this->now();
            $id = $this->gateway->insert('knowledge_items', ['type' => 'text', 'title' => $title, 'content' => $content, 'oss_file_index_id' => null, 'status' => ContentVersionRules::STATUS_NORMAL, 'created_by' => $context->actorId(), 'updated_by' => $context->actorId(), 'createtime' => $now, 'updatetime' => $now, 'deletetime' => null], $context);
            $this->audit('knowledge_created', 'knowledge_item', $id, $context, $ip, ['type' => 'text']);
            return $this->detail($id, $context, $ip);
        });
    }

    public function updateText(int $id, array $input, TenantContext $context, string $ip = ''): array
    {
        $this->fields($input, ['title', 'content']);
        $title = $this->title($input['title'] ?? null);
        $content = $this->text($input['content'] ?? null, 'Knowledge content', (int) $this->config['text']['knowledge_max_characters']);
        return $this->transaction(function () use ($id, $title, $content, $context, $ip): array {
            $row = $this->locked('knowledge_items', $this->id($id), $context, $ip);
            if ($row['type'] !== 'text') { $this->conflict('File knowledge content cannot be edited as text.'); }
            $this->gateway->update('knowledge_items', $id, ['title' => $title, 'content' => $content, 'updated_by' => $context->actorId(), 'updatetime' => $this->now()], $context, $ip);
            $this->audit('knowledge_updated', 'knowledge_item', $id, $context, $ip, ['type' => 'text']);
            return $this->detail($id, $context, $ip);
        });
    }

    public function createFile(array $input, string $path, string $originalName, string $declaredMime, int $declaredSize, TenantContext $context, string $ip = ''): array
    {
        $this->fields($input, ['title']);
        $title = $this->title($input['title'] ?? null);
        try { $upload = $this->validator->validate($path, $originalName, $declaredMime, $declaredSize); }
        catch (InvalidArgumentException $exception) { throw new ContentException(ApiErrorCode::INVALID_REQUEST, $exception->getMessage(), 422); }
        try { $stored = $this->storage->upload($upload, $context); }
        catch (StorageException $exception) { throw new ContentException(ApiErrorCode::DEPENDENCY_UNAVAILABLE, 'File storage is unavailable.', 503); }
        try {
            return $this->transaction(function () use ($upload, $stored, $title, $context, $ip): array {
                $now = $this->now();
                try {
                    $fileId = $this->gateway->insert('oss_file_indexes', ['provider' => $stored->provider(), 'remote_file_id' => $stored->remoteFileId(), 'object_key' => $stored->objectKey(), 'location_index' => $stored->locationIndex(), 'original_name' => $upload->originalName(), 'safe_name' => $upload->safeName(), 'extension' => $upload->extension(), 'declared_mime' => $upload->declaredMime(), 'detected_mime' => $upload->detectedMime(), 'size_bytes' => $upload->size(), 'sha256' => $upload->sha256(), 'status' => 'normal', 'metadata_json' => '{}', 'created_by' => $context->actorId(), 'updated_by' => $context->actorId(), 'createtime' => $now, 'updatetime' => $now, 'deletetime' => null], $context);
                } catch (PDOException $exception) {
                    if ($exception->getCode() === '23000') { $this->conflict('The same file already exists.'); }
                    throw $exception;
                }
                $id = $this->gateway->insert('knowledge_items', ['type' => $upload->type(), 'title' => $title, 'content' => null, 'oss_file_index_id' => $fileId, 'status' => 'normal', 'created_by' => $context->actorId(), 'updated_by' => $context->actorId(), 'createtime' => $now, 'updatetime' => $now, 'deletetime' => null], $context);
                $this->audit('file_uploaded', 'oss_file_index', $fileId, $context, $ip, ['type' => $upload->type(), 'size_bytes' => $upload->size()]);
                $this->audit('knowledge_created', 'knowledge_item', $id, $context, $ip, ['type' => $upload->type()]);
                return $this->detail($id, $context, $ip);
            });
        } catch (Throwable $exception) {
            try { $this->storage->delete($stored, $context); }
            catch (Throwable $cleanup) { $this->audit('file_cleanup_pending', 'oss_file_index', null, $context, $ip, ['remote_file_id_digest' => hash('sha256', $stored->remoteFileId())]); }
            throw $exception;
        }
    }

    public function detail(int $id, TenantContext $context, string $ip = ''): array
    {
        $row = $this->visible('knowledge_items', $this->id($id), $context, $ip);
        return $this->present($row, $context, $ip);
    }

    public function page(array $query, TenantContext $context): array
    {
        [$page, $perPage, $search, $sort, $direction] = $this->paginationInput($query, ['id', 'title', 'type', 'status', 'createtime', 'updatetime']);
        $filters = ['deletetime' => null];
        if (isset($query['type']) && $query['type'] !== '') {
            $type = (string) $query['type'];
            if (!in_array($type, ['text', 'image', 'document'], true)) { throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Knowledge type is invalid.', 422); }
            $filters['type'] = $type;
        }
        if (isset($query['status']) && $query['status'] !== '') { $filters['status'] = $this->status((string) $query['status']); }
        $result = $this->gateway->paginate('knowledge_items', $context, $filters, 'title', $search, $page, $perPage, $sort, $direction);
        $fileIds = array_values(array_unique(array_filter(array_map(static function (array $row): int {
            return $row['oss_file_index_id'] === null ? 0 : (int) $row['oss_file_index_id'];
        }, $result['items']))));
        $files = $fileIds === [] ? [] : $this->gateway->findMany('oss_file_indexes', $fileIds, $context);
        $byId = [];
        foreach ((array) $files as $file) { $byId[(int) $file['id']] = $file; }
        $result['items'] = array_map(function (array $row) use ($context, $byId): array {
            $file = $row['oss_file_index_id'] === null ? null : ($byId[(int) $row['oss_file_index_id']] ?? null);
            return $this->present($row, $context, '', $file);
        }, $result['items']);
        return $result;
    }

    public function setStatus(int $id, string $status, TenantContext $context, string $ip = ''): array
    {
        $status = $this->status($status);
        return $this->transaction(function () use ($id, $status, $context, $ip): array {
            $this->locked('knowledge_items', $this->id($id), $context, $ip);
            $this->gateway->update('knowledge_items', $id, ['status' => $status, 'updated_by' => $context->actorId(), 'updatetime' => $this->now()], $context, $ip);
            $this->audit('knowledge_status_changed', 'knowledge_item', $id, $context, $ip, ['status' => $status]);
            return $this->detail($id, $context, $ip);
        });
    }

    public function delete(int $id, TenantContext $context, string $ip = ''): void
    {
        $this->transaction(function () use ($id, $context, $ip): void {
            $row = $this->locked('knowledge_items', $this->id($id), $context, $ip);
            $now = $this->now();
            $this->gateway->update('knowledge_items', $id, ['status' => 'disabled', 'updated_by' => $context->actorId(), 'updatetime' => $now, 'deletetime' => $now], $context, $ip);
            if ($row['oss_file_index_id'] !== null) { $this->gateway->update('oss_file_indexes', (int) $row['oss_file_index_id'], ['status' => 'disabled', 'updated_by' => $context->actorId(), 'updatetime' => $now, 'deletetime' => $now], $context, $ip); }
            $this->audit('knowledge_deleted', 'knowledge_item', $id, $context, $ip, ['physical' => false]);
        });
    }

    public function preview(int $id, TenantContext $context, string $ip = ''): array
    {
        $row = $this->detail($id, $context, $ip);
        if ($row['type'] !== 'text') { return ['type' => $row['type'], 'file' => $row['file']]; }
        return ['type' => 'text', 'html' => SafePreview::plainText($row['content'])];
    }

    public function readFile(int $id, TenantContext $context, string $ip = ''): StorageReadResult
    {
        $row = $this->visible('knowledge_items', $this->id($id), $context, $ip);
        if ($row['oss_file_index_id'] === null) { $this->notFound(); }
        $file = $this->visible('oss_file_indexes', (int) $row['oss_file_index_id'], $context, $ip);
        try { return $this->storage->read($this->stored($file, $context), $context); }
        catch (StorageException $exception) { $this->notFound(); }
    }

    private function present(array $row, TenantContext $context, string $ip = '', ?array $knownFile = null): array
    {
        $file = null;
        if ($row['oss_file_index_id'] !== null) {
            $stored = $knownFile ?: $this->gateway->find('oss_file_indexes', (int) $row['oss_file_index_id'], $context, $ip);
            if (!is_array($stored)) { $this->notFound(); }
            $file = ['id' => (int) $stored['id'], 'provider' => $stored['provider'], 'original_name' => $stored['original_name'], 'safe_name' => $stored['safe_name'], 'extension' => $stored['extension'], 'mime' => $stored['detected_mime'], 'size_bytes' => (int) $stored['size_bytes'], 'status' => $stored['status']];
        }
        return ['id' => (int) $row['id'], 'type' => $row['type'], 'title' => $row['title'], 'content' => $row['content'], 'status' => $row['status'], 'file' => $file, 'created_by' => (int) $row['created_by'], 'updated_by' => (int) $row['updated_by'], 'createtime' => (int) $row['createtime'], 'updatetime' => (int) $row['updatetime']];
    }

    private function stored(array $file, TenantContext $context): StoredObject
    {
        return new StoredObject($file['provider'], $file['remote_file_id'], $file['object_key'], $file['location_index'], $this->companyId($context), $file['detected_mime'], (int) $file['size_bytes'], $file['sha256']);
    }

    private function title($value): string { return $this->text($value, 'Title', (int) $this->config['text']['title_max_characters']); }
    private function status(string $status): string
    {
        if (!in_array($status, ContentVersionRules::contentStatuses(), true)) { throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Status is invalid.', 422); }
        return $status;
    }
}

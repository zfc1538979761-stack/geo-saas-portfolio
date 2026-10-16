<?php

declare(strict_types=1);

namespace app\common\content;

use app\common\content\foundation\ContentVersionRules;
use app\common\foundation\ApiErrorCode;
use app\common\tenant\AuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use PDO;
use PDOException;

final class PromptTemplateService extends AbstractContentService
{
    public function __construct(PDO $pdo, TenantDataGateway $gateway, AuditLogger $audit, array $config, ?callable $clock = null)
    {
        parent::__construct($pdo, $gateway, $audit, $config, $clock);
    }

    public function create(array $input, TenantContext $context, string $ip = ''): array
    {
        $this->fields($input, ['name', 'scene', 'content']);
        $values = $this->values($input);
        return $this->transaction(function () use ($values, $context, $ip): array {
            $now = $this->now();
            $logicalKey = bin2hex(random_bytes(16));
            $id = $this->gateway->insert('prompt_templates', array_merge($values, ['logical_key' => $logicalKey, 'version_no' => 1, 'status' => ContentVersionRules::STATUS_NORMAL, 'created_by' => $context->actorId(), 'updated_by' => $context->actorId(), 'createtime' => $now, 'updatetime' => $now, 'deletetime' => null]), $context);
            $this->audit('prompt_created', 'prompt_template', $id, $context, $ip, ['logical_key' => $logicalKey, 'version_no' => 1]);
            return $this->version($id, $context, $ip);
        });
    }

    public function createVersion(string $logicalKey, array $input, TenantContext $context, string $ip = ''): array
    {
        $this->logicalKey($logicalKey);
        $this->fields($input, ['name', 'scene', 'content']);
        $values = $this->values($input);
        return $this->withLogicalLock($logicalKey, $context, function () use ($logicalKey, $values, $context, $ip): array {
            return $this->transaction(function () use ($logicalKey, $values, $context, $ip): array {
            $latest = $this->gateway->latestForUpdate('prompt_templates', $context, 'logical_key', $logicalKey, 'version_no', $ip);
            if (!is_array($latest) || $latest['deletetime'] !== null) { $this->notFound(); }
            $next = (int) $latest['version_no'] + 1;
            try {
                $now = $this->now();
                $id = $this->gateway->insert('prompt_templates', array_merge($values, ['logical_key' => $logicalKey, 'version_no' => $next, 'status' => ContentVersionRules::STATUS_NORMAL, 'created_by' => $context->actorId(), 'updated_by' => $context->actorId(), 'createtime' => $now, 'updatetime' => $now, 'deletetime' => null]), $context);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') { $this->conflict('Prompt version creation conflicted; retry with the latest version.'); }
                throw $exception;
            }
            $this->audit('prompt_version_created', 'prompt_template', $id, $context, $ip, ['logical_key' => $logicalKey, 'version_no' => $next]);
            return $this->version($id, $context, $ip);
            });
        });
    }

    public function version(int $id, TenantContext $context, string $ip = ''): array
    {
        return $this->present($this->visible('prompt_templates', $this->id($id), $context, $ip));
    }

    public function versions(string $logicalKey, TenantContext $context, string $ip = ''): array
    {
        $this->logicalKey($logicalKey);
        $rows = $this->gateway->list('prompt_templates', $context, ['logical_key' => $logicalKey], 500, 0);
        if ($rows === []) { $this->notFound(); }
        usort($rows, static function (array $a, array $b): int { return (int) $b['version_no'] <=> (int) $a['version_no']; });
        return array_map([$this, 'present'], $rows);
    }

    public function page(array $query, TenantContext $context): array
    {
        [$page, $perPage, $search, $sort, $direction] = $this->paginationInput($query, ['id', 'name', 'scene', 'version_no', 'status', 'createtime', 'updatetime']);
        $filters = ['deletetime' => null];
        if (isset($query['status']) && $query['status'] !== '') { $filters['status'] = $this->status((string) $query['status']); }
        if (isset($query['scene']) && $query['scene'] !== '') { $filters['scene'] = $this->text($query['scene'], 'Scene', 64); }
        $result = $this->gateway->paginateLatestByGroup('prompt_templates', $context, 'logical_key', $filters, 'name', $search, $page, $perPage, $sort, $direction);
        $result['items'] = array_map([$this, 'present'], $result['items']);
        return $result;
    }

    public function setStatus(string $logicalKey, string $status, TenantContext $context, string $ip = ''): array
    {
        $this->logicalKey($logicalKey);
        $status = $this->status($status);
        return $this->transaction(function () use ($logicalKey, $status, $context, $ip): array {
            $latest = $this->gateway->latestForUpdate('prompt_templates', $context, 'logical_key', $logicalKey, 'version_no', $ip);
            if (!is_array($latest) || $latest['deletetime'] !== null) { $this->notFound(); }
            $this->gateway->update('prompt_templates', (int) $latest['id'], ['status' => $status, 'updated_by' => $context->actorId(), 'updatetime' => $this->now()], $context, $ip);
            $this->audit('prompt_status_changed', 'prompt_template', (int) $latest['id'], $context, $ip, ['logical_key' => $logicalKey, 'status' => $status]);
            return $this->version((int) $latest['id'], $context, $ip);
        });
    }

    public function delete(string $logicalKey, TenantContext $context, string $ip = ''): void
    {
        $this->logicalKey($logicalKey);
        $this->transaction(function () use ($logicalKey, $context, $ip): void {
            $latest = $this->gateway->latestForUpdate('prompt_templates', $context, 'logical_key', $logicalKey, 'version_no', $ip);
            if (!is_array($latest) || $latest['deletetime'] !== null) { $this->notFound(); }
            $now = $this->now();
            $this->gateway->update('prompt_templates', (int) $latest['id'], ['status' => 'disabled', 'updated_by' => $context->actorId(), 'updatetime' => $now, 'deletetime' => $now], $context, $ip);
            $this->audit('prompt_deleted', 'prompt_template', (int) $latest['id'], $context, $ip, ['physical' => false]);
        });
    }

    private function values(array $input): array
    {
        return [
            'name' => $this->text($input['name'] ?? null, 'Prompt name', (int) $this->config['text']['title_max_characters']),
            'scene' => $this->text($input['scene'] ?? null, 'Prompt scene', 64),
            'content' => $this->text($input['content'] ?? null, 'Prompt content', (int) $this->config['text']['prompt_max_characters']),
        ];
    }

    private function withLogicalLock(string $logicalKey, TenantContext $context, callable $operation)
    {
        $lock = 'geo:t06:prompt:' . substr(hash('sha256', $this->companyId($context) . ':' . $logicalKey), 0, 40);
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
        $statement->execute(['lock_name' => $lock]);
        if ((int) $statement->fetchColumn() !== 1) {
            $this->conflict('Prompt version is busy; retry with the latest version.');
        }
        try { return $operation(); }
        finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $lock]);
        }
    }

    private function logicalKey(string $key): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) { throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Prompt logical key is invalid.', 422); }
    }

    private function status(string $status): string
    {
        if (!in_array($status, ContentVersionRules::contentStatuses(), true)) { throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Status is invalid.', 422); }
        return $status;
    }

    private function present(array $row): array
    {
        return ['id' => (int) $row['id'], 'logical_key' => $row['logical_key'], 'name' => $row['name'], 'scene' => $row['scene'], 'version_no' => (int) $row['version_no'], 'content' => $row['content'], 'status' => $row['status'], 'created_by' => (int) $row['created_by'], 'createtime' => (int) $row['createtime']];
    }
}

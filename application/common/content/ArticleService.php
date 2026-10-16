<?php

declare(strict_types=1);

namespace app\common\content;

use app\common\content\foundation\ContentVersionRules;
use app\common\content\foundation\SafePreview;
use app\common\foundation\ApiErrorCode;
use app\common\tenant\AuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use PDO;
use PDOException;

final class ArticleService extends AbstractContentService
{
    public function __construct(PDO $pdo, TenantDataGateway $gateway, AuditLogger $audit, array $config, ?callable $clock = null)
    {
        parent::__construct($pdo, $gateway, $audit, $config, $clock);
    }

    public function create(array $input, TenantContext $context, string $ip = ''): array
    {
        $this->fields($input, ['keyword_id', 'question_id', 'title', 'content', 'prompt_template_id', 'knowledge_item_ids']);
        $values = $this->versionInput($input);
        $keywordId = $this->id($input['keyword_id'] ?? null, 'Keyword ID');
        $questionId = $this->id($input['question_id'] ?? null, 'Question ID');
        return $this->transaction(function () use ($keywordId, $questionId, $values, $context, $ip): array {
            $this->locked('keywords', $keywordId, $context, $ip, ['status' => 'normal']);
            $this->locked('questions', $questionId, $context, $ip, ['keyword_id' => $keywordId, 'status' => 'normal']);
            [$prompt, $knowledge] = $this->lockContext($values['prompt_template_id'], $values['knowledge_item_ids'], $context, $ip);
            $now = $this->now();
            $articleId = $this->gateway->insert('articles', ['keyword_id' => $keywordId, 'question_id' => $questionId, 'title' => $values['title'], 'current_version_id' => null, 'status' => ContentVersionRules::ARTICLE_DRAFT, 'created_by' => $context->actorId(), 'updated_by' => $context->actorId(), 'createtime' => $now, 'updatetime' => $now, 'deletetime' => null], $context);
            $versionId = $this->insertVersion($articleId, 1, $values['title'], $values['content'], (int) $prompt['id'], $knowledge, $context, $now);
            $this->gateway->update('articles', $articleId, ['current_version_id' => $versionId], $context, $ip);
            $this->audit('article_created', 'article', $articleId, $context, $ip, ['version_no' => 1, 'keyword_id' => $keywordId, 'question_id' => $questionId]);
            $this->audit('article_version_created', 'article_version', $versionId, $context, $ip, ['article_id' => $articleId, 'version_no' => 1, 'source' => 'manual']);
            return $this->detail($articleId, $context, $ip);
        });
    }

    public function edit(int $articleId, array $input, TenantContext $context, string $ip = ''): array
    {
        $this->fields($input, ['current_version_id', 'title', 'content', 'prompt_template_id', 'knowledge_item_ids']);
        $expected = $this->id($input['current_version_id'] ?? null, 'Current version ID');
        $values = $this->versionInput($input);
        return $this->transaction(function () use ($articleId, $expected, $values, $context, $ip): array {
            $article = $this->locked('articles', $this->id($articleId), $context, $ip);
            if ((int) $article['current_version_id'] !== $expected) { $this->conflict('Article was changed by another request.'); }
            $current = $this->lockVersion($expected, $articleId, $context, $ip);
            [$prompt, $knowledge] = $this->lockContext($values['prompt_template_id'], $values['knowledge_item_ids'], $context, $ip);
            $next = (int) $current['version_no'] + 1;
            $now = $this->now();
            try { $versionId = $this->insertVersion($articleId, $next, $values['title'], $values['content'], (int) $prompt['id'], $knowledge, $context, $now); }
            catch (PDOException $exception) {
                if ($exception->getCode() === '23000') { $this->conflict('Article version conflicted; reload the current version.'); }
                throw $exception;
            }
            $this->gateway->update('articles', $articleId, ['title' => $values['title'], 'current_version_id' => $versionId, 'updated_by' => $context->actorId(), 'updatetime' => $now], $context, $ip);
            $this->audit('article_version_created', 'article_version', $versionId, $context, $ip, ['article_id' => $articleId, 'version_no' => $next, 'source' => 'manual']);
            return $this->detail($articleId, $context, $ip);
        });
    }

    public function restore(int $articleId, int $oldVersionId, int $expectedCurrentVersionId, TenantContext $context, string $ip = ''): array
    {
        return $this->transaction(function () use ($articleId, $oldVersionId, $expectedCurrentVersionId, $context, $ip): array {
            $article = $this->locked('articles', $this->id($articleId), $context, $ip);
            if ((int) $article['current_version_id'] !== $this->id($expectedCurrentVersionId, 'Current version ID')) { $this->conflict('Article was changed by another request.'); }
            $current = $this->lockVersion((int) $article['current_version_id'], $articleId, $context, $ip);
            $old = $this->lockVersion($this->id($oldVersionId), $articleId, $context, $ip);
            $relations = $this->gateway->list('article_version_knowledge', $context, ['article_version_id' => $oldVersionId], 100, 0);
            $knowledgeIds = array_map(static function (array $row): int { return (int) $row['knowledge_item_id']; }, $relations);
            $knowledge = $knowledgeIds === [] ? [] : $this->gateway->findMany('knowledge_items', $knowledgeIds, $context, [], true, $ip);
            if (!is_array($knowledge)) { $this->notFound(); }
            $prompt = $this->gateway->findMany('prompt_templates', [(int) $old['prompt_template_id']], $context, [], true, $ip);
            if (!is_array($prompt)) { $this->notFound(); }
            $next = (int) $current['version_no'] + 1;
            $now = $this->now();
            $versionId = $this->insertVersion($articleId, $next, $old['title_snapshot'], $old['content'], (int) $old['prompt_template_id'], $knowledge, $context, $now);
            $this->gateway->update('articles', $articleId, ['title' => $old['title_snapshot'], 'current_version_id' => $versionId, 'updated_by' => $context->actorId(), 'updatetime' => $now], $context, $ip);
            $this->audit('article_version_restored', 'article_version', $versionId, $context, $ip, ['article_id' => $articleId, 'copied_from_version_id' => $oldVersionId, 'version_no' => $next]);
            return $this->detail($articleId, $context, $ip);
        });
    }

    public function detail(int $id, TenantContext $context, string $ip = ''): array
    {
        $article = $this->visible('articles', $this->id($id), $context, $ip);
        if ($article['current_version_id'] === null) { throw new ContentException(ApiErrorCode::INTERNAL_ERROR, 'Article version invariant is broken.', 500); }
        $result = $this->presentArticle($article);
        $result['current_version'] = $this->version($id, (int) $article['current_version_id'], $context, $ip);
        return $result;
    }

    public function page(array $query, TenantContext $context): array
    {
        [$page, $perPage, $search, $sort, $direction] = $this->paginationInput($query, ['id', 'title', 'status', 'createtime', 'updatetime']);
        $filters = ['deletetime' => null];
        if (isset($query['status']) && $query['status'] !== '') { $filters['status'] = $this->articleStatus((string) $query['status']); }
        $result = $this->gateway->paginate('articles', $context, $filters, 'title', $search, $page, $perPage, $sort, $direction);
        $versionIds = array_values(array_filter(array_map(static function (array $row): int { return (int) $row['current_version_id']; }, $result['items'])));
        $versions = $versionIds === [] ? [] : $this->gateway->findMany('article_versions', $versionIds, $context);
        $byId = [];
        foreach ((array) $versions as $version) { $byId[(int) $version['id']] = ['id' => (int) $version['id'], 'version_no' => (int) $version['version_no'], 'source' => $version['source']]; }
        $result['items'] = array_map(function (array $row) use ($byId): array { $item = $this->presentArticle($row); $item['current_version'] = $byId[(int) $row['current_version_id']] ?? null; return $item; }, $result['items']);
        return $result;
    }

    public function versions(int $articleId, TenantContext $context, string $ip = ''): array
    {
        $this->visible('articles', $this->id($articleId), $context, $ip);
        $rows = $this->gateway->list('article_versions', $context, ['article_id' => $articleId], 500, 0);
        usort($rows, static function (array $a, array $b): int { return (int) $b['version_no'] <=> (int) $a['version_no']; });
        if ($rows === []) { return []; }
        $promptIds = array_values(array_unique(array_map(static function (array $row): int { return (int) $row['prompt_template_id']; }, $rows)));
        $prompts = $this->gateway->findMany('prompt_templates', $promptIds, $context);
        if (!is_array($prompts)) { $this->notFound(); }
        $promptsById = [];
        foreach ($prompts as $prompt) { $promptsById[(int) $prompt['id']] = $prompt; }
        $versionIds = array_map(static function (array $row): int { return (int) $row['id']; }, $rows);
        $relations = $this->gateway->listByForeignIds('article_version_knowledge', 'article_version_id', $versionIds, $context);
        $relationsByVersion = [];
        foreach ($relations as $relation) { $relationsByVersion[(int) $relation['article_version_id']][] = $relation; }
        return array_map(function (array $row) use ($context, $ip, $promptsById, $relationsByVersion): array {
            return $this->presentVersion($row, $context, $ip, $promptsById[(int) $row['prompt_template_id']] ?? null, $relationsByVersion[(int) $row['id']] ?? []);
        }, $rows);
    }

    public function version(int $articleId, int $versionId, TenantContext $context, string $ip = ''): array
    {
        $this->visible('articles', $this->id($articleId), $context, $ip);
        $row = $this->gateway->find('article_versions', $this->id($versionId), $context, $ip);
        if (!is_array($row) || (int) $row['article_id'] !== $articleId) { $this->notFound(); }
        return $this->presentVersion($row, $context, $ip);
    }

    public function preview(int $articleId, ?int $versionId, TenantContext $context, string $ip = ''): array
    {
        $article = $this->detail($articleId, $context, $ip);
        $version = $versionId === null ? $article['current_version'] : $this->version($articleId, $versionId, $context, $ip);
        return ['article_id' => $articleId, 'version_id' => $version['id'], 'version_no' => $version['version_no'], 'title' => htmlspecialchars($version['title_snapshot'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'html' => SafePreview::plainText($version['content'])];
    }

    public function setStatus(int $articleId, string $status, TenantContext $context, string $ip = ''): array
    {
        $status = $this->articleStatus($status);
        return $this->transaction(function () use ($articleId, $status, $context, $ip): array {
            $article = $this->locked('articles', $this->id($articleId), $context, $ip);
            if (!ContentVersionRules::canTransitionArticle((string) $article['status'], $status)) { $this->conflict('Article status transition is not allowed.'); }
            $this->gateway->update('articles', $articleId, ['status' => $status, 'updated_by' => $context->actorId(), 'updatetime' => $this->now()], $context, $ip);
            $this->audit('article_status_changed', 'article', $articleId, $context, $ip, ['from' => $article['status'], 'to' => $status]);
            return $this->detail($articleId, $context, $ip);
        });
    }

    public function context(int $articleId, TenantContext $context, string $ip = ''): array
    {
        $article = $this->detail($articleId, $context, $ip);
        return ['article' => $article, 'usable_for_new_operations' => $article['status'] === ContentVersionRules::ARTICLE_DRAFT];
    }

    private function versionInput(array $input): array
    {
        return [
            'title' => $this->text($input['title'] ?? null, 'Article title', (int) $this->config['text']['title_max_characters']),
            'content' => $this->text($input['content'] ?? null, 'Article content', (int) $this->config['text']['article_max_characters']),
            'prompt_template_id' => $this->id($input['prompt_template_id'] ?? null, 'Prompt version ID'),
            'knowledge_item_ids' => $this->ids($input['knowledge_item_ids'] ?? [], 'Knowledge item IDs'),
        ];
    }

    private function lockContext(int $promptId, array $knowledgeIds, TenantContext $context, string $ip): array
    {
        $prompt = $this->locked('prompt_templates', $promptId, $context, $ip, ['status' => 'normal']);
        $knowledge = $knowledgeIds === [] ? [] : $this->gateway->findMany('knowledge_items', $knowledgeIds, $context, ['status' => 'normal', 'deletetime' => null], true, $ip);
        if (!is_array($knowledge)) { $this->notFound(); }
        return [$prompt, $knowledge];
    }

    private function insertVersion(int $articleId, int $versionNo, string $title, string $content, int $promptId, array $knowledge, TenantContext $context, int $now): int
    {
        $versionId = $this->gateway->insert('article_versions', ['article_id' => $articleId, 'version_no' => $versionNo, 'title_snapshot' => $title, 'content' => $content, 'source' => ContentVersionRules::articleSource(), 'prompt_template_id' => $promptId, 'created_by' => $context->actorId(), 'createtime' => $now], $context);
        foreach ($knowledge as $item) {
            $snapshot = ['id' => (int) $item['id'], 'type' => $item['type'], 'title' => $item['title'], 'status' => $item['status'], 'content_sha256' => $item['content'] === null ? null : hash('sha256', (string) $item['content']), 'oss_file_index_id' => $item['oss_file_index_id'] === null ? null : (int) $item['oss_file_index_id']];
            $this->gateway->insert('article_version_knowledge', ['article_version_id' => $versionId, 'knowledge_item_id' => (int) $item['id'], 'knowledge_snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'createtime' => $now], $context);
        }
        return $versionId;
    }

    private function lockVersion(int $versionId, int $articleId, TenantContext $context, string $ip): array
    {
        $rows = $this->gateway->findMany('article_versions', [$versionId], $context, ['article_id' => $articleId], true, $ip);
        if (!is_array($rows)) { $this->notFound(); }
        return $rows[0];
    }

    private function presentArticle(array $row): array
    {
        return ['id' => (int) $row['id'], 'keyword_id' => (int) $row['keyword_id'], 'question_id' => (int) $row['question_id'], 'title' => $row['title'], 'current_version_id' => (int) $row['current_version_id'], 'status' => $row['status'], 'created_by' => (int) $row['created_by'], 'updated_by' => (int) $row['updated_by'], 'createtime' => (int) $row['createtime'], 'updatetime' => (int) $row['updatetime']];
    }

    private function presentVersion(array $row, TenantContext $context, string $ip, ?array $knownPrompt = null, ?array $knownRelations = null): array
    {
        $prompt = $knownPrompt ?: $this->gateway->find('prompt_templates', (int) $row['prompt_template_id'], $context, $ip);
        if (!is_array($prompt)) { $this->notFound(); }
        $relations = $knownRelations === null
            ? $this->gateway->list('article_version_knowledge', $context, ['article_version_id' => (int) $row['id']], 100, 0)
            : $knownRelations;
        $knowledge = array_map(static function (array $relation): array {
            try { $snapshot = json_decode($relation['knowledge_snapshot_json'], true, 32, JSON_THROW_ON_ERROR); }
            catch (\Throwable $exception) { $snapshot = []; }
            return ['knowledge_item_id' => (int) $relation['knowledge_item_id'], 'snapshot' => $snapshot];
        }, $relations);
        return ['id' => (int) $row['id'], 'article_id' => (int) $row['article_id'], 'version_no' => (int) $row['version_no'], 'title_snapshot' => $row['title_snapshot'], 'content' => $row['content'], 'source' => $row['source'], 'prompt' => ['id' => (int) $prompt['id'], 'logical_key' => $prompt['logical_key'], 'version_no' => (int) $prompt['version_no'], 'name' => $prompt['name'], 'scene' => $prompt['scene']], 'knowledge' => $knowledge, 'created_by' => (int) $row['created_by'], 'createtime' => (int) $row['createtime']];
    }

    private function articleStatus(string $status): string
    {
        if (!in_array($status, ContentVersionRules::articleStatuses(), true)) { throw new ContentException(ApiErrorCode::INVALID_REQUEST, 'Article status is invalid.', 422); }
        return $status;
    }
}

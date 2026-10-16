<?php

declare(strict_types=1);

namespace app\common\ai\task;

use app\common\tenant\AuditLogger;
use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use PDO;
use PDOException;
use Throwable;

final class AiArticleWriter
{
    private PDO $pdo;
    private TenantDataGateway $gateway;
    private AuditLogger $audit;
    private $clock;

    public function __construct(PDO $pdo, TenantDataGateway $gateway, AuditLogger $audit, ?callable $clock = null)
    {
        $this->pdo = $pdo;
        $this->gateway = $gateway;
        $this->audit = $audit;
        $this->clock = $clock ?: static fn(): int => time();
    }

    public function write(array $snapshot, array $output, int $aiCallId, TenantContext $context): array
    {
        $this->pdo->beginTransaction();
        try {
            $target = (array) ($snapshot['target'] ?? []);
            $articleId = (int) ($target['article_id'] ?? 0);
            $now = (int) call_user_func($this->clock);
            if ($articleId > 0) {
                $rows = $this->gateway->findMany('articles', [$articleId], $context, ['deletetime' => null], true);
                if (!is_array($rows)) { throw new AiTaskException('Article target is unavailable.', 404); }
                $article = $rows[0];
                $expected = (int) ($target['expected_current_version_id'] ?? 0);
                if ((int) $article['current_version_id'] !== $expected) {
                    throw new AiTaskException('Article changed after the AI task was created.', 409);
                }
                $current = $this->gateway->findMany('article_versions', [$expected], $context, ['article_id' => $articleId], true);
                if (!is_array($current)) { throw new AiTaskException('Article version target is unavailable.', 409); }
                $versionNo = (int) $current[0]['version_no'] + 1;
            } else {
                $articleId = $this->gateway->insert('articles', [
                    'keyword_id' => (int) $snapshot['keyword']['id'],
                    'question_id' => (int) $snapshot['question']['id'],
                    'title' => $output['title'], 'current_version_id' => null, 'status' => 'draft',
                    'created_by' => $context->actorId(), 'updated_by' => $context->actorId(),
                    'createtime' => $now, 'updatetime' => $now, 'deletetime' => null,
                ], $context);
                $versionNo = 1;
            }
            $versionData = [
                'article_id' => $articleId, 'version_no' => $versionNo,
                'title_snapshot' => $output['title'], 'content' => $output['content'], 'source' => 'ai',
                'prompt_template_id' => (int) $snapshot['prompt']['id'], 'ai_call_id' => $aiCallId,
                'created_by' => $context->actorId(), 'createtime' => $now,
            ];
            if (isset($snapshot['geo_target_answer']['id'])) {
                $versionData['geo_answer_version_id'] = (int) $snapshot['geo_target_answer']['id'];
            }
            $versionId = $this->gateway->insert('article_versions', $versionData, $context);
            foreach ((array) ($snapshot['knowledge'] ?? []) as $item) {
                $knowledgeSnapshot = [
                    'id' => (int) $item['id'], 'type' => 'text', 'title' => (string) $item['title'],
                    'status' => (string) $item['status'], 'content_sha256' => (string) $item['content_sha256'],
                    'oss_file_index_id' => null,
                ];
                $this->gateway->insert('article_version_knowledge', [
                    'article_version_id' => $versionId, 'knowledge_item_id' => (int) $item['id'],
                    'knowledge_snapshot_json' => json_encode($knowledgeSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'createtime' => $now,
                ], $context);
            }
            $this->gateway->update('articles', $articleId, [
                'title' => $output['title'], 'current_version_id' => $versionId,
                'updated_by' => $context->actorId(), 'updatetime' => $now,
            ], $context);
            $this->audit->record('ai_article_version_created', $context, 'article_version', (string) $versionId, '', [
                'article_id' => $articleId, 'version_no' => $versionNo, 'ai_call_id' => $aiCallId,
            ]);
            $this->pdo->commit();
            return ['article_id' => $articleId, 'version_id' => $versionId, 'version_no' => $versionNo];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                throw new AiTaskException('Article version conflicted with another operation.', 409);
            }
            throw $exception;
        }
    }
}

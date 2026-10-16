<?php

declare(strict_types=1);

namespace app\common\geo;

use app\common\tenant\TenantContext;
use app\common\tenant\TenantDataGateway;
use PDO;

final class GeoArticleContextService
{
    private PDO $pdo;
    private TenantDataGateway $gateway;

    public function __construct(PDO $pdo, TenantDataGateway $gateway)
    {
        $this->pdo = $pdo; $this->gateway = $gateway;
    }

    public function context(int $answerVersionId, TenantContext $context): array
    {
        $row = $this->gateway->find('geo_answer_versions', $answerVersionId, $context);
        if (!is_array($row)) { throw new GeoException('GEO answer version was not found.', 404); }
        if ($row['status'] !== 'confirmed') { throw new GeoException('Only a confirmed GEO answer can enter article generation.', 409); }
        return [
            'answer_version_id' => (int) $row['id'], 'question_id' => (int) $row['question_id'],
            'answer' => (string) $row['answer_text'], 'recommendation_reason' => $row['recommendation_reason'],
            'facts' => json_decode((string) $row['facts_json'], true, 64, JSON_THROW_ON_ERROR),
            'profile_snapshot' => json_decode((string) $row['profile_snapshot_json'], true, 64, JSON_THROW_ON_ERROR),
            'knowledge_snapshot' => json_decode((string) $row['knowledge_snapshot_json'], true, 64, JSON_THROW_ON_ERROR),
        ];
    }

    public function attach(int $answerVersionId, int $articleVersionId, TenantContext $context): void
    {
        $answer = $this->context($answerVersionId, $context);
        $version = $this->gateway->find('article_versions', $articleVersionId, $context);
        if (!is_array($version)) { throw new GeoException('Article version was not found.', 404); }
        $article = $this->gateway->find('articles', (int) $version['article_id'], $context);
        if (!is_array($article) || (int) $article['question_id'] !== (int) $answer['question_id']) {
            throw new GeoException('GEO answer and article question do not match.', 409);
        }
        $this->gateway->update('article_versions', $articleVersionId, ['geo_answer_version_id' => $answerVersionId], $context);
    }
}

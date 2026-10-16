<?php

declare(strict_types=1);

namespace app\common\geo;

use app\common\tenant\TenantContext;
use PDO;

final class VisibilityPublicationTimeline
{
    private PDO $pdo;
    private string $prefix;

    public function __construct(PDO $pdo, string $prefix)
    {
        if (preg_match('/^[A-Za-z0-9_]*$/D', $prefix) !== 1) {
            throw new GeoException('Publication timeline table prefix is invalid.', 500);
        }
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }

    public function firstPublishedAt(int $questionId, TenantContext $context): ?string
    {
        if ($questionId < 1) { throw new GeoException('Question ID is invalid.'); }
        $statement = $this->pdo->prepare(
            'SELECT MIN(l.`created_at`) FROM `' . $this->prefix . 'order_status_logs` l'
            . ' INNER JOIN `' . $this->prefix . 'publish_orders` o'
            . ' ON o.`company_id`=l.`company_id` AND o.`id`=l.`order_id`'
            . ' INNER JOIN `' . $this->prefix . 'articles` a'
            . ' ON a.`company_id`=o.`company_id` AND a.`id`=o.`article_id`'
            . ' WHERE l.`company_id`=? AND a.`question_id`=? AND l.`to_status`=\'published\''
        );
        $statement->execute([(int) $context->companyId(), $questionId]);
        $value = $statement->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function successfulBaselineResultId(
        int $questionId,
        string $platform,
        string $model,
        string $provider,
        string $classifierVersion,
        TenantContext $context
    ): ?int {
        $statement = $this->pdo->prepare(
            'SELECT r.`id` FROM `' . $this->prefix . 'geo_visibility_results` r'
            . ' INNER JOIN `' . $this->prefix . 'geo_visibility_checks` c'
            . ' ON c.`company_id`=r.`company_id` AND c.`id`=r.`check_id`'
            . ' WHERE r.`company_id`=? AND r.`question_id`=? AND r.`platform`=? AND r.`model_name`=?'
            . ' AND r.`classifier_version`=? AND c.`provider_code`=? AND c.`check_type`=\'baseline\''
            . ' AND c.`status`=\'succeeded\' ORDER BY r.`detected_at`,r.`id` LIMIT 1'
        );
        $statement->execute([
            (int) $context->companyId(), $questionId, $platform, $model, $classifierVersion, $provider,
        ]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (int) $value;
    }
}

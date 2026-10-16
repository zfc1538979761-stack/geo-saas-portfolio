<?php

declare(strict_types=1);

namespace app\common\tenant;

use app\common\foundation\SensitiveDataRedactor;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoAuditLogger implements AuditLogger
{
    private $pdo;
    private $table;
    private $clock;

    public function __construct(PDO $pdo, string $prefix, ?callable $clock = null)
    {
        if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
            throw new TenantException('Audit table prefix is invalid.');
        }
        $this->pdo = $pdo;
        $this->table = $prefix . 'audit_logs';
        $this->clock = $clock ?: static function (): DateTimeImmutable {
            return new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        };
    }

    public function record(
        string $action,
        ?TenantContext $context,
        string $targetType,
        ?string $targetId,
        string $ip,
        array $detail = []
    ): void {
        $action = trim($action);
        $targetType = trim($targetType);
        if ($action === '' || strlen($action) > 100 || $targetType === '' || strlen($targetType) > 100) {
            throw new TenantException('Audit action or target type is invalid.');
        }

        $redacted = SensitiveDataRedactor::redact($detail);
        $encoded = json_encode(
            $redacted,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $occurredAt = call_user_func($this->clock);
        if (!$occurredAt instanceof DateTimeImmutable) {
            throw new TenantException('Audit clock is invalid.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO `' . $this->table . '`'
            . ' (`actor_id`,`actor_type`,`company_id`,`action`,`target_type`,`target_id`,'
            . '`ip_address`,`detail_json`,`created_at`)'
            . ' VALUES (:actor_id,:actor_type,:company_id,:action,:target_type,:target_id,'
            . ':ip_address,:detail_json,:created_at)'
        );
        $statement->execute([
            'actor_id' => $context === null ? 0 : $context->actorId(),
            'actor_type' => $context === null ? 'system' : $context->actorType(),
            'company_id' => $context === null ? null : $context->companyId(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip_address' => substr(trim($ip), 0, 45),
            'detail_json' => $encoded,
            'created_at' => $occurredAt->format('Y-m-d H:i:s.u'),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace app\common\internal;

use app\common\foundation\SensitiveDataRedactor;
use PDO;

final class InternalAuditWriter
{
    private PDO $pdo;
    private string $table;

    public function __construct(PDO $pdo, string $prefix)
    {
        if (preg_match('/^[A-Za-z0-9_]*$/D', $prefix) !== 1) {
            throw new \InvalidArgumentException('Internal audit table prefix is invalid.');
        }
        $this->pdo = $pdo;
        $this->table = $prefix . 'audit_logs';
    }

    public function record(
        int $actorId,
        string $action,
        ?int $companyId,
        string $targetType,
        ?string $targetId,
        string $ip,
        array $detail = []
    ): void {
        $action = trim($action);
        $targetType = trim($targetType);
        if ($actorId < 1 || $action === '' || $targetType === '') {
            throw new \InvalidArgumentException('Internal audit record is invalid.');
        }
        $encoded = json_encode(
            SensitiveDataRedactor::redact($detail),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO `' . $this->table . '`'
            . ' (`actor_id`,`actor_type`,`company_id`,`action`,`target_type`,`target_id`,'
            . '`ip_address`,`detail_json`,`created_at`)'
            . ' VALUES (:actor_id,:actor_type,:company_id,:action,:target_type,:target_id,'
            . ':ip_address,:detail_json,:created_at)'
        );
        $stmt->execute([
            'actor_id' => $actorId,
            'actor_type' => 'admin',
            'company_id' => $companyId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip_address' => substr(trim($ip), 0, 45),
            'detail_json' => $encoded,
            'created_at' => date('Y-m-d H:i:s.u'),
        ]);
    }
}

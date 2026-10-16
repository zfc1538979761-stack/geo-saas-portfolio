<?php

declare(strict_types=1);

namespace app\common\tenant;

use PDO;

/**
 * Central tenant-safe access to the existing companies identity root.
 *
 * companies predates the tenant resource catalog and uses id itself as the
 * tenant key. No caller-supplied company identifier is accepted here.
 */
final class TenantCompanyGateway
{
    private $pdo;
    private $table;

    public function __construct(PDO $pdo, string $prefix)
    {
        if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
            throw new TenantException('Company table prefix is invalid.');
        }
        $this->pdo = $pdo;
        $this->table = $prefix . 'companies';
    }

    public function find(TenantContext $context): ?array
    {
        return $this->findInternal($context, false);
    }

    public function findForUpdate(TenantContext $context): ?array
    {
        if (!$this->pdo->inTransaction()) {
            throw new TenantException('Company row locking requires an active transaction.');
        }
        return $this->findInternal($context, true);
    }

    private function findInternal(TenantContext $context, bool $forUpdate): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT `id`,`name`,`status`,`createtime`,`updatetime`'
            . ' FROM `' . $this->table . '` WHERE `id`=:company_id LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['company_id' => $this->companyId($context)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function updateName(string $name, int $updatedAt, TenantContext $context): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . $this->table . '` SET `name`=:name,`updatetime`=:updated_at'
            . ' WHERE `id`=:company_id'
        );
        $statement->execute([
            'name' => $name,
            'updated_at' => $updatedAt,
            'company_id' => $this->companyId($context),
        ]);
        if ($statement->rowCount() > 0) {
            return true;
        }
        $current = $this->find($context);
        return $current !== null && (string) $current['name'] === $name;
    }

    private function companyId(TenantContext $context): int
    {
        $companyId = $context->companyId();
        if ($companyId === null || $companyId < 1 || $context->canBypass()) {
            throw new TenantException('Enterprise tenant context is required.');
        }
        return $companyId;
    }
}

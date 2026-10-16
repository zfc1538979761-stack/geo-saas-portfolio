<?php

declare(strict_types=1);

namespace app\common\tenant;

use PDO;
use Throwable;

final class TenantDataGateway
{
    private $pdo;
    private $registry;
    private $auditLogger;

    public function __construct(PDO $pdo, TenantTableRegistry $registry, AuditLogger $auditLogger)
    {
        $this->pdo = $pdo;
        $this->registry = $registry;
        $this->auditLogger = $auditLogger;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function insert(string $resource, array $data, TenantContext $context): int
    {
        $definition = $this->registry->require($resource);
        $companyId = $this->requiredCompanyId($context);
        $values = $this->writableData($definition, $data);
        $values['company_id'] = $companyId;
        if ($values === ['company_id' => $companyId]) {
            throw new TenantException('Tenant insert contains no writable data.');
        }

        $columns = array_keys($values);
        $placeholders = array_map(static function (string $column): string {
            return ':' . $column;
        }, $columns);
        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $this->quote($definition->table())
            . ' (' . implode(',', array_map([$this, 'quote'], $columns)) . ')'
            . ' VALUES (' . implode(',', $placeholders) . ')'
        );
        $statement->execute($values);
        return (int) $this->pdo->lastInsertId();
    }

    public function find(
        string $resource,
        int $id,
        TenantContext $context,
        string $ip = ''
    ): ?array {
        $definition = $this->registry->require($resource);
        $params = ['id' => $id];
        $scope = $this->scopeSql($context, '', $params);
        $statement = $this->pdo->prepare(
            'SELECT * FROM ' . $this->quote($definition->table())
            . ' WHERE `id`=:id' . $scope . ' LIMIT 1'
        );
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $this->denied($resource, $id, $context, $ip);
            return null;
        }
        return $row;
    }

    public function list(
        string $resource,
        TenantContext $context,
        array $filters = [],
        int $limit = 100,
        int $offset = 0
    ): array {
        $definition = $this->registry->require($resource);
        $params = [];
        $where = $this->scopeSql($context, '', $params);
        $where .= $this->filtersSql($definition, $filters, '', $params);
        $statement = $this->pdo->prepare(
            'SELECT * FROM ' . $this->quote($definition->table())
            . ' WHERE 1=1' . $where
            . ' ORDER BY `id` ASC LIMIT ' . $this->limit($limit) . ' OFFSET ' . $this->offset($offset)
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function update(
        string $resource,
        int $id,
        array $data,
        TenantContext $context,
        string $ip = ''
    ): bool {
        $definition = $this->registry->require($resource);
        $values = $this->writableData($definition, $data);
        if ($values === []) {
            throw new TenantException('Tenant update contains no writable data.');
        }
        $assignments = [];
        $params = ['id' => $id];
        foreach ($values as $column => $value) {
            $assignments[] = $this->quote($column) . '=:' . $column;
            $params[$column] = $value;
        }
        $scope = $this->scopeSql($context, '', $params);
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->quote($definition->table())
            . ' SET ' . implode(',', $assignments)
            . ' WHERE `id`=:id' . $scope
        );
        $statement->execute($params);
        if ($statement->rowCount() < 1) {
            if ($this->scopedExists($definition, $id, $context)) {
                return true;
            }
            $this->denied($resource, $id, $context, $ip);
            return false;
        }
        return true;
    }

    public function delete(
        string $resource,
        int $id,
        TenantContext $context,
        string $ip = ''
    ): bool {
        $definition = $this->registry->require($resource);
        $params = ['id' => $id];
        $scope = $this->scopeSql($context, '', $params);
        $statement = $this->pdo->prepare(
            'DELETE FROM ' . $this->quote($definition->table())
            . ' WHERE `id`=:id' . $scope
        );
        $statement->execute($params);
        if ($statement->rowCount() < 1) {
            $this->denied($resource, $id, $context, $ip);
            return false;
        }
        return true;
    }

    public function updateMany(
        string $resource,
        array $ids,
        array $data,
        TenantContext $context,
        string $ip = '',
        array $requiredFilters = []
    ): bool {
        $definition = $this->registry->require($resource);
        $values = $this->writableData($definition, $data);
        if ($values === []) {
            throw new TenantException('Tenant batch update contains no writable data.');
        }
        return $this->batch(
            $resource,
            $definition,
            $ids,
            $context,
            $ip,
            $requiredFilters,
            function (string $idSql, array $idParams) use (
                $definition,
                $values,
                $context,
                $requiredFilters
            ): void {
                $params = $idParams;
                $assignments = [];
                foreach ($values as $column => $value) {
                    $key = 'set_' . $column;
                    $assignments[] = $this->quote($column) . '=:' . $key;
                    $params[$key] = $value;
                }
                $scope = $this->scopeSql($context, '', $params);
                $scope .= $this->filtersSql($definition, $requiredFilters, '', $params, 'required_');
                $statement = $this->pdo->prepare(
                    'UPDATE ' . $this->quote($definition->table())
                    . ' SET ' . implode(',', $assignments)
                    . ' WHERE `id` IN (' . $idSql . ')' . $scope
                );
                $statement->execute($params);
            }
        );
    }

    public function deleteMany(
        string $resource,
        array $ids,
        TenantContext $context,
        string $ip = ''
    ): bool {
        $definition = $this->registry->require($resource);
        return $this->batch(
            $resource,
            $definition,
            $ids,
            $context,
            $ip,
            [],
            function (string $idSql, array $idParams) use ($definition, $context): void {
                $params = $idParams;
                $scope = $this->scopeSql($context, '', $params);
                $statement = $this->pdo->prepare(
                    'DELETE FROM ' . $this->quote($definition->table())
                    . ' WHERE `id` IN (' . $idSql . ')' . $scope
                );
                $statement->execute($params);
            }
        );
    }

    public function count(string $resource, TenantContext $context, array $filters = []): int
    {
        $definition = $this->registry->require($resource);
        $params = [];
        $where = $this->scopeSql($context, '', $params);
        $where .= $this->filtersSql($definition, $filters, '', $params);
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . $this->quote($definition->table()) . ' WHERE 1=1' . $where
        );
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    public function sum(string $resource, string $column, TenantContext $context): float
    {
        $definition = $this->registry->require($resource);
        if (!$definition->allowsAggregate($column)) {
            throw new TenantException('Tenant aggregate column is not registered.');
        }
        $params = [];
        $scope = $this->scopeSql($context, '', $params);
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(' . $this->quote($column) . '),0)'
            . ' FROM ' . $this->quote($definition->table()) . ' WHERE 1=1' . $scope
        );
        $statement->execute($params);
        return (float) $statement->fetchColumn();
    }

    public function search(
        string $resource,
        TenantContext $context,
        string $column,
        string $term,
        int $page,
        int $perPage
    ): array {
        $definition = $this->registry->require($resource);
        if (!$definition->allowsSearch($column)) {
            throw new TenantException('Tenant search column is not registered.');
        }
        $page = $this->page($page);
        $perPage = $this->limit($perPage);
        $params = ['search' => '%' . $term . '%'];
        $scope = $this->scopeSql($context, '', $params);
        $where = $scope . ' AND ' . $this->quote($column) . ' LIKE :search';
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . $this->quote($definition->table()) . ' WHERE 1=1' . $where
        );
        $count->execute($params);
        $items = $this->pdo->prepare(
            'SELECT * FROM ' . $this->quote($definition->table())
            . ' WHERE 1=1' . $where . ' ORDER BY `id` ASC'
            . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $items->execute($params);
        return [
            'items' => $items->fetchAll(PDO::FETCH_ASSOC),
            'total' => (int) $count->fetchColumn(),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function paginate(
        string $resource,
        TenantContext $context,
        array $filters,
        ?string $searchColumn,
        string $searchTerm,
        int $page,
        int $perPage,
        string $sortColumn,
        string $sortDirection
    ): array {
        $definition = $this->registry->require($resource);
        if (!$definition->allowsSort($sortColumn)) {
            throw new TenantException('Tenant sort column is not registered.');
        }
        $sortDirection = strtolower($sortDirection);
        if (!in_array($sortDirection, ['asc', 'desc'], true)) {
            throw new TenantException('Tenant sort direction is invalid.');
        }
        $page = $this->page($page);
        $perPage = $this->limit($perPage);
        $params = [];
        $where = $this->scopeSql($context, '', $params);
        $where .= $this->filtersSql($definition, $filters, '', $params);
        if ($searchColumn !== null && $searchTerm !== '') {
            if (!$definition->allowsSearch($searchColumn)) {
                throw new TenantException('Tenant search column is not registered.');
            }
            $where .= ' AND ' . $this->quote($searchColumn) . " LIKE :literal_search ESCAPE '='";
            $params['literal_search'] = '%' . $this->escapeLike($searchTerm) . '%';
        }
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . $this->quote($definition->table()) . ' WHERE 1=1' . $where
        );
        $count->execute($params);
        $items = $this->pdo->prepare(
            'SELECT * FROM ' . $this->quote($definition->table()) . ' WHERE 1=1' . $where
            . ' ORDER BY ' . $this->quote($sortColumn) . ' ' . strtoupper($sortDirection) . ',`id` '
            . strtoupper($sortDirection)
            . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $items->execute($params);
        return [
            'items' => $items->fetchAll(PDO::FETCH_ASSOC),
            'total' => (int) $count->fetchColumn(),
            'page' => $page,
            'per_page' => $perPage,
            'sort' => $sortColumn,
            'direction' => $sortDirection,
        ];
    }

    public function findMany(
        string $resource,
        array $ids,
        TenantContext $context,
        array $filters = [],
        bool $forUpdate = false,
        string $ip = ''
    ): ?array {
        $definition = $this->registry->require($resource);
        $ids = $this->normalizedIds($ids, 'Tenant multi-find IDs are invalid.');
        if ($forUpdate && !$this->pdo->inTransaction()) {
            throw new TenantException('Tenant multi-find locking requires a transaction.');
        }
        [$idSql, $params] = $this->idParameters($ids);
        $where = $this->scopeSql($context, '', $params);
        $where .= $this->filtersSql($definition, $filters, '', $params);
        $statement = $this->pdo->prepare(
            'SELECT * FROM ' . $this->quote($definition->table())
            . ' WHERE `id` IN (' . $idSql . ')' . $where . ' ORDER BY `id` ASC'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($ids)) {
            $this->denied($resource, null, $context, $ip);
            return null;
        }
        return $rows;
    }

    public function latestForUpdate(
        string $resource,
        TenantContext $context,
        string $groupColumn,
        $groupValue,
        string $orderColumn,
        string $ip = ''
    ): ?array {
        if (!$this->pdo->inTransaction()) {
            throw new TenantException('Tenant latest-row locking requires a transaction.');
        }
        $definition = $this->registry->require($resource);
        if (!$definition->allowsFilter($groupColumn) || !$definition->allowsSort($orderColumn)) {
            throw new TenantException('Tenant latest-row columns are not registered.');
        }
        $params = ['group_value' => $groupValue];
        $scope = $this->scopeSql($context, '', $params);
        $statement = $this->pdo->prepare(
            'SELECT * FROM ' . $this->quote($definition->table())
            . ' WHERE ' . $this->quote($groupColumn) . '=:group_value' . $scope
            . ' ORDER BY ' . $this->quote($orderColumn) . ' DESC,`id` DESC LIMIT 1 FOR UPDATE'
        );
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $this->denied($resource, null, $context, $ip);
            return null;
        }
        return $row;
    }

    public function paginateLatestByGroup(
        string $resource,
        TenantContext $context,
        string $groupColumn,
        array $filters,
        ?string $searchColumn,
        string $searchTerm,
        int $page,
        int $perPage,
        string $sortColumn,
        string $sortDirection
    ): array {
        $definition = $this->registry->require($resource);
        if (!$definition->allowsFilter($groupColumn) || !$definition->allowsSort($sortColumn)) {
            throw new TenantException('Tenant grouped pagination columns are not registered.');
        }
        $sortDirection = strtolower($sortDirection);
        if (!in_array($sortDirection, ['asc', 'desc'], true)) {
            throw new TenantException('Tenant sort direction is invalid.');
        }
        $page = $this->page($page);
        $perPage = $this->limit($perPage);
        $params = [];
        $where = $this->scopeSql($context, 'm', $params);
        $where .= $this->filtersSql($definition, $filters, 'm', $params);
        if ($searchColumn !== null && $searchTerm !== '') {
            if (!$definition->allowsSearch($searchColumn)) {
                throw new TenantException('Tenant search column is not registered.');
            }
            $where .= ' AND `m`.' . $this->quote($searchColumn) . " LIKE :literal_search ESCAPE '='";
            $params['literal_search'] = '%' . $this->escapeLike($searchTerm) . '%';
        }
        $latest = 'SELECT `company_id`,' . $this->quote($groupColumn) . ',MAX(`version_no`) AS `latest_version`'
            . ' FROM ' . $this->quote($definition->table()) . ' GROUP BY `company_id`,' . $this->quote($groupColumn);
        $from = ' FROM ' . $this->quote($definition->table()) . ' m INNER JOIN (' . $latest . ') l'
            . ' ON `l`.`company_id`=`m`.`company_id` AND `l`.' . $this->quote($groupColumn)
            . '=`m`.' . $this->quote($groupColumn) . ' AND `l`.`latest_version`=`m`.`version_no`'
            . ' WHERE 1=1' . $where;
        $count = $this->pdo->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);
        $items = $this->pdo->prepare(
            'SELECT `m`.*' . $from . ' ORDER BY `m`.' . $this->quote($sortColumn) . ' '
            . strtoupper($sortDirection) . ',`m`.`id` ' . strtoupper($sortDirection)
            . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $items->execute($params);
        return ['items' => $items->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn(), 'page' => $page, 'per_page' => $perPage, 'sort' => $sortColumn, 'direction' => $sortDirection];
    }

    public function listByForeignIds(
        string $resource,
        string $foreignColumn,
        array $foreignIds,
        TenantContext $context
    ): array {
        $definition = $this->registry->require($resource);
        if (!$definition->allowsFilter($foreignColumn)) {
            throw new TenantException('Tenant foreign-list column is not registered.');
        }
        $foreignIds = $this->normalizedIds($foreignIds, 'Tenant foreign-list IDs are invalid.');
        [$idSql, $params] = $this->idParameters($foreignIds);
        $scope = $this->scopeSql($context, '', $params);
        $statement = $this->pdo->prepare(
            'SELECT * FROM ' . $this->quote($definition->table())
            . ' WHERE ' . $this->quote($foreignColumn) . ' IN (' . $idSql . ')' . $scope
            . ' ORDER BY ' . $this->quote($foreignColumn) . ' ASC,`id` ASC'
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function joinList(
        string $resource,
        string $relatedResource,
        string $foreignColumn,
        string $relatedColumn,
        TenantContext $context
    ): array {
        $main = $this->registry->require($resource);
        $related = $this->registry->require($relatedResource);
        if (!$main->allowsFilter($foreignColumn) || !$related->allowsFilter($relatedColumn)) {
            throw new TenantException('Tenant join columns are not registered.');
        }
        $params = [];
        $scope = $this->scopeSql($context, 'm', $params)
            . $this->scopeSql($context, 'r', $params, 'related_company_id');
        $statement = $this->pdo->prepare(
            'SELECT m.*,r.* FROM ' . $this->quote($main->table()) . ' m'
            . ' INNER JOIN ' . $this->quote($related->table()) . ' r'
            . ' ON m.' . $this->quote($foreignColumn) . '=r.' . $this->quote($relatedColumn)
            . ' AND m.`company_id`=r.`company_id`'
            . ' WHERE 1=1' . $scope . ' ORDER BY m.`id` ASC'
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function whereRelatedExists(
        string $resource,
        string $relatedResource,
        string $foreignColumn,
        string $relatedColumn,
        array $relatedFilters,
        TenantContext $context
    ): array {
        $main = $this->registry->require($resource);
        $related = $this->registry->require($relatedResource);
        if (!$main->allowsFilter($foreignColumn) || !$related->allowsFilter($relatedColumn)) {
            throw new TenantException('Tenant subquery columns are not registered.');
        }
        $params = [];
        $mainScope = $this->scopeSql($context, 'm', $params);
        $relatedScope = $this->scopeSql($context, 'r', $params, 'related_company_id');
        $relatedWhere = $this->filtersSql($related, $relatedFilters, 'r', $params, 'related_');
        $statement = $this->pdo->prepare(
            'SELECT m.* FROM ' . $this->quote($main->table()) . ' m WHERE 1=1' . $mainScope
            . ' AND EXISTS (SELECT 1 FROM ' . $this->quote($related->table()) . ' r'
            . ' WHERE r.' . $this->quote($relatedColumn) . '=m.' . $this->quote($foreignColumn)
            . ' AND r.`company_id`=m.`company_id`' . $relatedScope . $relatedWhere . ')'
            . ' ORDER BY m.`id` ASC'
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function batch(
        string $resource,
        TenantTableDefinition $definition,
        array $ids,
        TenantContext $context,
        string $ip,
        array $requiredFilters,
        callable $operation
    ): bool {
        $ids = $this->normalizedIds($ids, 'Tenant batch IDs are invalid.');
        [$idSql, $idParams] = $this->idParameters($ids);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $params = $idParams;
            $scope = $this->scopeSql($context, '', $params);
            $scope .= $this->filtersSql($definition, $requiredFilters, '', $params, 'required_');
            $check = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . $this->quote($definition->table())
                . ' WHERE `id` IN (' . $idSql . ')' . $scope . ' FOR UPDATE'
            );
            $check->execute($params);
            if ((int) $check->fetchColumn() !== count($ids)) {
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                }
                $this->denied($resource, null, $context, $ip);
                return false;
            }
            $operation($idSql, $idParams);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function idParameters(array $ids): array
    {
        $placeholders = [];
        $params = [];
        foreach ($ids as $position => $id) {
            $key = 'id_' . $position;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        return [implode(',', $placeholders), $params];
    }

    private function scopeSql(
        TenantContext $context,
        string $alias,
        array &$params,
        string $parameter = 'company_id'
    ): string {
        if ($context->canBypass()) {
            return '';
        }
        $companyId = $this->requiredCompanyId($context);
        $params[$parameter] = $companyId;
        $prefix = $alias === '' ? '' : $this->quote($alias) . '.';
        return ' AND ' . $prefix . '`company_id`=:' . $parameter;
    }

    private function filtersSql(
        TenantTableDefinition $definition,
        array $filters,
        string $alias,
        array &$params,
        string $parameterPrefix = 'filter_'
    ): string {
        $sql = '';
        $prefix = $alias === '' ? '' : $this->quote($alias) . '.';
        foreach ($filters as $column => $value) {
            $column = (string) $column;
            if ($column === 'company_id') {
                continue;
            }
            if (!$definition->allowsFilter($column)) {
                throw new TenantException('Tenant filter column is not registered.');
            }
            if ($value === null) {
                $sql .= ' AND ' . $prefix . $this->quote($column) . ' IS NULL';
            } else {
                $parameter = $parameterPrefix . $column;
                $sql .= ' AND ' . $prefix . $this->quote($column) . '=:' . $parameter;
                $params[$parameter] = $value;
            }
        }
        return $sql;
    }

    private function writableData(TenantTableDefinition $definition, array $data): array
    {
        $values = [];
        foreach ($definition->writableColumns() as $column) {
            if (array_key_exists($column, $data)) {
                $values[$column] = $data[$column];
            }
        }
        return $values;
    }

    private function requiredCompanyId(TenantContext $context): int
    {
        $companyId = $context->companyId();
        if ($companyId === null || $companyId < 1) {
            throw new TenantException('Tenant context is required.');
        }
        return $companyId;
    }

    private function denied(
        string $resource,
        ?int $id,
        TenantContext $context,
        string $ip
    ): void {
        $this->auditLogger->record(
            'tenant_access_denied',
            $context,
            $resource,
            $id === null ? null : (string) $id,
            $ip,
            ['outcome' => 'not_found']
        );
    }

    private function scopedExists(
        TenantTableDefinition $definition,
        int $id,
        TenantContext $context
    ): bool {
        $params = ['exists_id' => $id];
        $scope = $this->scopeSql($context, '', $params, 'exists_company_id');
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM ' . $this->quote($definition->table())
            . ' WHERE `id`=:exists_id' . $scope . ' LIMIT 1'
        );
        $statement->execute($params);
        return $statement->fetchColumn() !== false;
    }

    private function limit(int $limit): int
    {
        return max(1, min(500, $limit));
    }

    private function page(int $page): int
    {
        if ($page < 1 || $page > 100000) {
            throw new TenantException('Tenant page is outside the supported range.');
        }
        return $page;
    }

    private function normalizedIds(array $ids, string $message): array
    {
        if ($ids === []) {
            throw new TenantException($message);
        }
        $normalized = [];
        $maximum = (string) PHP_INT_MAX;
        foreach ($ids as $id) {
            if (is_int($id)) {
                if ($id < 1) {
                    throw new TenantException($message);
                }
                $normalized[] = $id;
                continue;
            }
            if (!is_string($id) || preg_match('/^[1-9][0-9]*$/D', $id) !== 1) {
                throw new TenantException($message);
            }
            if (strlen($id) > strlen($maximum) || (strlen($id) === strlen($maximum) && strcmp($id, $maximum) > 0)) {
                throw new TenantException($message);
            }
            $normalized[] = (int) $id;
        }
        return array_values(array_unique($normalized));
    }

    private function offset(int $offset): int
    {
        return max(0, $offset);
    }

    private function escapeLike(string $value): string
    {
        return strtr($value, ['=' => '==', '%' => '=%', '_' => '=_']);
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new TenantException('Tenant SQL identifier is invalid.');
        }
        return '`' . $identifier . '`';
    }
}

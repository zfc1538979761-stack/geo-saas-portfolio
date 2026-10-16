<?php

declare(strict_types=1);

namespace app\common\tenant;

final class TenantTableDefinition
{
    private $table;
    private $writableColumns;
    private $searchableColumns;
    private $aggregateColumns;
    private $sortableColumns;

    public function __construct(
        string $table,
        array $writableColumns,
        array $searchableColumns = [],
        array $aggregateColumns = [],
        array $sortableColumns = []
    ) {
        $this->assertIdentifier($table);
        foreach (array_merge($writableColumns, $searchableColumns, $aggregateColumns, $sortableColumns) as $column) {
            $this->assertIdentifier((string) $column);
            if (in_array($column, ['id', 'company_id'], true)) {
                throw new TenantException('Reserved tenant columns cannot be configured as mutable.');
            }
        }

        $this->table = $table;
        $this->writableColumns = array_values(array_unique(array_map('strval', $writableColumns)));
        $this->searchableColumns = array_values(array_unique(array_map('strval', $searchableColumns)));
        $this->aggregateColumns = array_values(array_unique(array_map('strval', $aggregateColumns)));
        $this->sortableColumns = array_values(array_unique(array_map('strval', $sortableColumns)));
    }

    public function table(): string
    {
        return $this->table;
    }

    public function writableColumns(): array
    {
        return $this->writableColumns;
    }

    public function allowsFilter(string $column): bool
    {
        return $column === 'id' || in_array($column, $this->writableColumns, true);
    }

    public function allowsSearch(string $column): bool
    {
        return in_array($column, $this->searchableColumns, true);
    }

    public function allowsAggregate(string $column): bool
    {
        return in_array($column, $this->aggregateColumns, true);
    }

    public function allowsSort(string $column): bool
    {
        return $column === 'id' || in_array($column, $this->sortableColumns, true);
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new TenantException('Tenant table identifier is invalid.');
        }
    }
}

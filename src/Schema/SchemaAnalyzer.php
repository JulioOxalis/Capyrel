<?php

namespace Julio\Capyrel\Schema;

use Illuminate\Support\Str;
use Julio\Capyrel\Drivers\DriverFactory;
use Julio\Capyrel\Drivers\SchemaDriverInterface;

class SchemaAnalyzer
{
    private SchemaDriverInterface $driver;
    private array $tables      = [];
    private array $columns     = [];
    private array $foreignKeys = [];
    private array $indexes     = [];

    public function analyze(string $connection = ''): void
    {
        $this->driver = DriverFactory::make($connection);

        $this->tables = $this->driver->getTables();

        foreach ($this->tables as $table) {
            $this->columns[$table]     = $this->driver->getColumns($table);
            $this->foreignKeys[$table] = $this->driver->getForeignKeys($table);
            $this->indexes[$table]     = $this->driver->getIndexes($table);
        }
    }

    public function getDriver(): SchemaDriverInterface
    {
        return $this->driver;
    }

    public function getTables(): array
    {
        return $this->tables;
    }

    public function getColumns(string $table): array
    {
        return $this->columns[$table] ?? [];
    }

    public function getColumnNames(string $table): array
    {
        return collect($this->getColumns($table))->pluck('name')->toArray();
    }

    public function getForeignKeys(string $table): array
    {
        return $this->foreignKeys[$table] ?? [];
    }

    public function getIndexes(string $table): array
    {
        return $this->indexes[$table] ?? [];
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->getColumnNames($table));
    }

    public function isUniqueColumn(string $table, string $column): bool
    {
        foreach ($this->getIndexes($table) as $index) {
            if (($index['unique'] ?? false) && in_array($column, $index['columns'])) {
                return true;
            }
        }
        return false;
    }

    public function hasIndex(string $table, string $column): bool
    {
        foreach ($this->getIndexes($table) as $index) {
            if (in_array($column, $index['columns'])) {
                return true;
            }
        }
        return false;
    }

    public function hasSoftDeletes(string $table): bool
    {
        return $this->hasColumn($table, 'deleted_at');
    }

    public function isPivotTable(string $table): bool
    {
        $fks = $this->getForeignKeys($table);
        if (count($fks) < 2) {
            return $this->nameConventionPivot($table) && $this->hasOnlyFkLikeColumns($table);
        }

        $referencedTables = collect($fks)->pluck('foreign_table')->unique()->count();
        if ($referencedTables < 2) return false;

        return $this->nameConventionPivot($table) || $this->hasOnlyFkLikeColumns($table);
    }

    private function nameConventionPivot(string $table): bool
    {
        foreach ($this->tables as $tableA) {
            foreach ($this->tables as $tableB) {
                if ($tableA === $tableB || $tableA === $table || $tableB === $table) continue;
                $sA = Str::singular($tableA);
                $sB = Str::singular($tableB);
                if ($table === "{$sA}_{$sB}" || $table === "{$sB}_{$sA}") return true;
            }
        }
        return false;
    }

    private function hasOnlyFkLikeColumns(string $table): bool
    {
        $system  = ['id', 'created_at', 'updated_at', 'deleted_at'];
        $cols    = $this->getColumnNames($table);
        $fkCols  = collect($this->getForeignKeys($table))->flatMap(fn($fk) => $fk['columns'])->toArray();

        foreach ($cols as $col) {
            if (in_array($col, $system)) continue;
            if (in_array($col, $fkCols)) continue;
            if (Str::endsWith($col, '_id')) continue;
            return false;
        }
        return true;
    }

    public function getMorphColumns(string $table): array
    {
        $columns = $this->getColumnNames($table);
        $morphs  = [];
        foreach ($columns as $col) {
            if (Str::endsWith($col, '_type') && in_array(Str::beforeLast($col, '_type') . '_id', $columns)) {
                $morphs[] = Str::beforeLast($col, '_type');
            }
        }
        return $morphs;
    }

    public function getTablesPointingTo(string $table): array
    {
        $expectedFk = Str::singular($table) . '_id';
        $result     = [];
        foreach ($this->tables as $t) {
            if ($t === $table || $this->isPivotTable($t)) continue;
            if ($this->hasColumn($t, $expectedFk)) $result[] = $t;
        }
        return $result;
    }

    /** All _id columns that look like FK references */
    public function getAllFkColumns(): array
    {
        $result = [];
        foreach ($this->tables as $table) {
            if ($this->isPivotTable($table)) continue;
            foreach ($this->getColumnNames($table) as $col) {
                if (Str::endsWith($col, '_id') && $col !== 'id') {
                    $result[] = ['table' => $table, 'column' => $col];
                }
            }
        }
        return $result;
    }
}

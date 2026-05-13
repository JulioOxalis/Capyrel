<?php

namespace Julio\Capyrel\Drivers;

use Illuminate\Support\Facades\Schema;

class SqlDriver implements SchemaDriverInterface
{
    private mixed $schema;

    public function __construct(string $connection = '')
    {
        $this->schema = $connection
            ? Schema::connection($connection)
            : Schema::getFacadeRoot();
    }

    public function getTables(): array
    {
        return collect($this->schema->getTables())->pluck('name')->toArray();
    }

    public function getColumns(string $table): array
    {
        return $this->schema->getColumns($table);
    }

    public function getForeignKeys(string $table): array
    {
        return $this->schema->getForeignKeys($table);
    }

    public function getIndexes(string $table): array
    {
        return $this->schema->getIndexes($table);
    }

    public function getDriverName(): string
    {
        return 'sql';
    }
}

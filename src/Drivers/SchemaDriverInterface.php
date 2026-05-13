<?php

namespace Julio\Capyrel\Drivers;

interface SchemaDriverInterface
{
    /** All table / collection names */
    public function getTables(): array;

    /** Columns for a table — each item has at minimum: name, type_name, nullable */
    public function getColumns(string $table): array;

    /** Foreign key constraints — each item: columns[], foreign_table, foreign_columns[] */
    public function getForeignKeys(string $table): array;

    /** Indexes — each item: columns[], unique (bool), primary (bool) */
    public function getIndexes(string $table): array;

    /** 'sql' | 'mongodb' */
    public function getDriverName(): string;
}

<?php

namespace Julio\Capyrel\Analyzers;

use Illuminate\Support\Str;
use Julio\Capyrel\Schema\SchemaAnalyzer;

/**
 * Finds _id columns that reference tables which don't exist in the schema.
 * These are likely typos, renamed tables, or forgotten migrations.
 */
class OrphanForeignKeyAnalyzer
{
    public function analyze(SchemaAnalyzer $schema): array
    {
        $diagnostics = [];
        $allTables   = $schema->getTables();

        foreach ($allTables as $table) {
            // Check formal FK constraints first
            foreach ($schema->getForeignKeys($table) as $fk) {
                if (!in_array($fk['foreign_table'], $allTables)) {
                    $diagnostics[] = Diagnostic::error(
                        'orphan_fk',
                        '',
                        "{$table}.{$fk['columns'][0]} references '{$fk['foreign_table']}' which does not exist",
                        "Create a migration for '{$fk['foreign_table']}' or fix the foreign key reference — this will cause runtime errors"
                    );
                }
            }

            // Check convention-based _id columns
            foreach ($schema->getColumnNames($table) as $col) {
                if (!str_ends_with($col, '_id') || $col === 'id') continue;

                // Already caught by FK constraint check
                $isFormal = collect($schema->getForeignKeys($table))
                    ->flatMap(fn($fk) => $fk['columns'])
                    ->contains($col);

                if ($isFormal) continue;

                $guessedTable = Str::plural(Str::beforeLast($col, '_id'));

                if (!in_array($guessedTable, $allTables)) {
                    $diagnostics[] = Diagnostic::warning(
                        'orphan_fk',
                        '',
                        "{$table}.{$col} looks like it references '{$guessedTable}' but that table doesn't exist",
                        "Check if '{$guessedTable}' needs a migration, or if '{$col}' is intentionally a non-relational field"
                    );
                }
            }
        }

        return $diagnostics;
    }
}

<?php

namespace Julio\Capyrel\Analyzers;

use Julio\Capyrel\Schema\SchemaAnalyzer;

/**
 * Detects foreign key columns that have no database index.
 * Unindexed FKs cause full table scans on every JOIN / eager load.
 */
class MissingIndexAnalyzer
{
    public function analyze(SchemaAnalyzer $schema): array
    {
        $diagnostics = [];

        foreach ($schema->getTables() as $table) {
            if ($schema->isPivotTable($table)) continue;

            foreach ($schema->getForeignKeys($table) as $fk) {
                $col = $fk['columns'][0] ?? null;
                if (!$col) continue;

                if (!$schema->hasIndex($table, $col)) {
                    $diagnostics[] = Diagnostic::warning(
                        'missing_index',
                        '',
                        "{$table}.{$col} is a foreign key with no index",
                        "Add \$table->index('{$col}') to your {$table} migration — unindexed FKs cause full table scans on every eager load"
                    );
                }
            }

            // Also check convention-based _id columns
            foreach ($schema->getColumnNames($table) as $col) {
                if (!str_ends_with($col, '_id') || $col === 'id') continue;
                if ($schema->hasIndex($table, $col)) continue;

                // Only warn once (FK constraint already caught above)
                $alreadyCaught = collect($schema->getForeignKeys($table))
                    ->flatMap(fn($fk) => $fk['columns'])
                    ->contains($col);

                if (!$alreadyCaught) {
                    $diagnostics[] = Diagnostic::info(
                        'missing_index',
                        '',
                        "{$table}.{$col} looks like a foreign key but has no index",
                        "Consider \$table->index('{$col}') — or add a foreign key constraint"
                    );
                }
            }
        }

        return $diagnostics;
    }
}

<?php

namespace Julio\Capyrel\Analyzers;

use Julio\Capyrel\Schema\SchemaAnalyzer;

/**
 * X10 FEATURE: Detects FK constraints without ON DELETE CASCADE.
 * Deleting a parent record will leave orphaned child rows silently.
 */
class CascadeRiskAnalyzer
{
    public function analyze(SchemaAnalyzer $schema): array
    {
        $diagnostics = [];

        foreach ($schema->getTables() as $table) {
            foreach ($schema->getForeignKeys($table) as $fk) {
                $onDelete = strtolower($fk['on_delete'] ?? 'no action');

                if (in_array($onDelete, ['no action', 'restrict', ''])) {
                    $col    = $fk['columns'][0] ?? $table;
                    $parent = $fk['foreign_table'];

                    $diagnostics[] = Diagnostic::warning(
                        'cascade_risk',
                        '',
                        "{$table}.{$col} → {$parent} has no ON DELETE CASCADE",
                        "Deleting a {$parent} record will leave orphaned {$table} rows. Add ->cascadeOnDelete() to your migration, or handle deletion explicitly in an observer."
                    );
                }
            }
        }

        return $diagnostics;
    }
}

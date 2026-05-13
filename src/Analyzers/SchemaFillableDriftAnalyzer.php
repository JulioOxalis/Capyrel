<?php

namespace Julio\Capyrel\Analyzers;

use Julio\Capyrel\Schema\SchemaAnalyzer;

/**
 * Compares each model's $fillable array against actual table columns.
 * Flags drift: columns in $fillable that don't exist in the table,
 * and table columns not yet declared as fillable.
 *
 * Skipped for MongoDB — MongoDB is schemaless and $fillable is not
 * required to match a fixed column list.
 */
class SchemaFillableDriftAnalyzer
{
    public function analyze(SchemaAnalyzer $schema): array
    {
        // MongoDB has no fixed schema — $fillable rules don't apply
        if ($schema->getDriver()->getDriverName() === 'mongodb') {
            return [];
        }

        $diagnostics = [];

        foreach ($schema->getTables() as $table) {
            if ($schema->isPivotTable($table)) continue;

            $modelName = $this->tableToModel($table);
            $modelPath = app_path("Models/{$modelName}.php");

            if (!file_exists($modelPath)) continue;

            $content         = file_get_contents($modelPath);
            $actualColumns   = $schema->getColumnNames($table);
            $fillable        = $this->extractFillable($content);

            if (empty($fillable)) continue;

            // 1. Columns in $fillable that don't exist in the table
            foreach ($fillable as $field) {
                if (!in_array($field, $actualColumns)) {
                    $diagnostics[] = Diagnostic::error(
                        'fillable_drift',
                        $modelName,
                        "{$modelName}::\$fillable contains '{$field}' but column doesn't exist in {$table}",
                        "Remove '{$field}' from \$fillable or create a migration to add the column — mass assignment may silently fail"
                    );
                }
            }

            // 2. Non-system columns in the table that are not in $fillable (and not _id, timestamps)
            $skip = ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at'];
            foreach ($actualColumns as $col) {
                if (in_array($col, $skip)) continue;
                if (str_ends_with($col, '_id')) continue; // FK columns intentionally excluded
                if (!in_array($col, $fillable)) {
                    $diagnostics[] = Diagnostic::info(
                        'fillable_drift',
                        $modelName,
                        "{$modelName}::\$fillable is missing '{$col}' (column exists in {$table})",
                        "Add '{$col}' to \$fillable if it should be mass-assignable, or keep it guarded intentionally"
                    );
                }
            }
        }

        return $diagnostics;
    }

    private function extractFillable(string $content): array
    {
        if (preg_match('/\$fillable\s*=\s*\[([^\]]+)\]/s', $content, $m)) {
            preg_match_all("/'([^']+)'/", $m[1], $strings);
            return $strings[1] ?? [];
        }
        return [];
    }

    private function tableToModel(string $table): string
    {
        return \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($table));
    }
}

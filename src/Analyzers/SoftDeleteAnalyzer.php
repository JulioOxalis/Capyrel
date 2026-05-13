<?php

namespace Julio\Capyrel\Analyzers;

use Julio\Capyrel\Schema\SchemaAnalyzer;

/**
 * Detects tables with deleted_at columns and checks if:
 * 1. The model uses the SoftDeletes trait
 * 2. Related models that query this model should use ->withTrashed() hints
 */
class SoftDeleteAnalyzer
{
    public function analyze(array $relationships, SchemaAnalyzer $schema): array
    {
        $diagnostics    = [];
        $softDeletedModels = [];

        foreach ($schema->getTables() as $table) {
            if (!$schema->hasSoftDeletes($table)) continue;

            $modelName = $this->tableToModel($table);
            $softDeletedModels[] = $modelName;

            $modelPath = app_path("Models/{$modelName}.php");
            if (!file_exists($modelPath)) continue;

            $content = file_get_contents($modelPath);

            if (!str_contains($content, 'SoftDeletes')) {
                $diagnostics[] = Diagnostic::warning(
                    'soft_delete',
                    $modelName,
                    "{$table} has a deleted_at column but {$modelName} doesn't use the SoftDeletes trait",
                    "Add: use Illuminate\\Database\\Eloquent\\SoftDeletes; and 'use SoftDeletes;' inside {$modelName}"
                );
            }
        }

        // Warn about relationships pointing to soft-deleted models
        foreach ($relationships as $modelName => $rels) {
            foreach ($rels as $rel) {
                if (empty($rel['related'])) continue;
                if (!in_array($rel['related'], $softDeletedModels)) continue;

                $diagnostics[] = Diagnostic::info(
                    'soft_delete',
                    $modelName,
                    "{$modelName}::{$rel['method']}() → {$rel['related']} which uses soft deletes",
                    "Use ->withTrashed() or ->onlyTrashed() when you need to include deleted {$rel['related']} records"
                );
            }
        }

        return $diagnostics;
    }

    private function tableToModel(string $table): string
    {
        return \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($table));
    }
}

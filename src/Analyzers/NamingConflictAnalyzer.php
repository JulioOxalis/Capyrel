<?php

namespace Julio\Capyrel\Analyzers;

/**
 * Detects when a capyrel-generated method name would clash with an existing
 * method already defined in the model file.
 */
class NamingConflictAnalyzer
{
    public function analyze(array $relationships): array
    {
        $diagnostics = [];

        foreach ($relationships as $modelName => $rels) {
            $modelPath = app_path("Models/{$modelName}.php");
            if (!file_exists($modelPath)) continue;

            $content = file_get_contents($modelPath);
            preg_match_all('/public\s+function\s+(\w+)\s*\(/', $content, $matches);
            $existingMethods = $matches[1] ?? [];

            foreach ($rels as $rel) {
                if (empty($rel['method'])) continue;

                if (in_array($rel['method'], $existingMethods)) {
                    $diagnostics[] = Diagnostic::warning(
                        'naming_conflict',
                        $modelName,
                        "{$modelName}::{$rel['method']}() already exists — capyrel will skip it",
                        "If the existing method is different from the detected {$rel['type']}, rename one to avoid confusion"
                    );
                }

                // Warn on reserved Laravel method names
                $reserved = ['boot', 'booted', 'create', 'find', 'update', 'delete', 'save', 'fill', 'fresh', 'replicate'];
                if (in_array($rel['method'], $reserved)) {
                    $diagnostics[] = Diagnostic::error(
                        'naming_conflict',
                        $modelName,
                        "Generated method name '{$rel['method']}' conflicts with a built-in Laravel Model method",
                        "Rename the relationship method manually — e.g. '{$rel['method']}Relation' or use a custom name in belongsTo/hasMany"
                    );
                }
            }
        }

        return $diagnostics;
    }
}

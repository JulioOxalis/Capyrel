<?php

namespace Julio\Capyrel\Analyzers;

use Illuminate\Support\Str;
use Julio\Capyrel\Schema\SchemaAnalyzer;

/**
 * X10 FEATURE: Finds all morphTo relationships, then scans models to
 * discover which classes would be valid polymorphic types.
 * Suggests adding Relation::morphMap([...]) to prevent storing full
 * class names in the database (security + portability concern).
 */
class MorphTypeRegistryAnalyzer
{
    public function analyze(array $relationships, SchemaAnalyzer $schema): array
    {
        $diagnostics = [];
        $morphTos    = [];

        // Collect all morphTo relationships
        foreach ($relationships as $modelName => $rels) {
            foreach ($rels as $rel) {
                if ($rel['type'] === 'morphTo') {
                    $morphTos[] = [
                        'model'  => $modelName,
                        'method' => $rel['method'],
                        'name'   => $rel['name'] ?? $rel['method'],
                    ];
                }
            }
        }

        if (empty($morphTos)) return $diagnostics;

        // Check if morphMap is already registered
        $serviceProviderPath = app_path('Providers/AppServiceProvider.php');
        $hasMorphMap = file_exists($serviceProviderPath) &&
            str_contains(file_get_contents($serviceProviderPath), 'morphMap');

        foreach ($morphTos as $morph) {
            $morphName  = $morph['name'];
            $candidates = $this->findMorphCandidates($morphName, $relationships, $schema);
            $mapEntries = collect($candidates)
                ->map(fn($m) => "'{$this->toMorphKey($m)}' => {$m}::class")
                ->implode(', ');

            if (!$hasMorphMap) {
                $diagnostics[] = Diagnostic::warning(
                    'morph_registry',
                    $morph['model'],
                    "{$morph['model']}::{$morph['method']}() uses morphTo but no Relation::morphMap() found",
                    "Add to AppServiceProvider::boot(): Relation::morphMap([{$mapEntries}]); — storing full class names in DB breaks on namespace refactoring"
                );
            } else {
                $diagnostics[] = Diagnostic::info(
                    'morph_registry',
                    $morph['model'],
                    "morphMap detected — verify it includes: [{$mapEntries}]",
                    "Ensure all polymorphic types for '{$morphName}' are registered in your morphMap"
                );
            }
        }

        return $diagnostics;
    }

    private function findMorphCandidates(string $morphName, array $relationships, SchemaAnalyzer $schema): array
    {
        // Any model that has a morphMany/morphOne using this morph name
        $candidates = [];
        foreach ($relationships as $modelName => $rels) {
            foreach ($rels as $rel) {
                if (isset($rel['name']) && $rel['name'] === $morphName) {
                    $candidates[] = $modelName;
                }
            }
        }

        // Also scan model files for morphMany($model, '{morphName}')
        $modelsPath = app_path('Models');
        if (is_dir($modelsPath)) {
            foreach (glob("{$modelsPath}/*.php") as $file) {
                $content   = file_get_contents($file);
                $className = basename($file, '.php');
                if (str_contains($content, "'{$morphName}'") && !in_array($className, $candidates)) {
                    $candidates[] = $className;
                }
            }
        }

        return array_unique($candidates);
    }

    private function toMorphKey(string $modelName): string
    {
        return Str::snake($modelName);
    }
}

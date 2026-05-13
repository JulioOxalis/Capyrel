<?php

namespace Julio\Capyrel\Analyzers;

/**
 * Scores hasManyThrough chains by depth.
 * Deep chains = expensive queries + risk of Cartesian product explosion.
 */
class EagerLoadDepthAnalyzer
{
    public function analyze(array $relationships): array
    {
        $diagnostics = [];

        foreach ($relationships as $modelName => $rels) {
            foreach ($rels as $rel) {
                if ($rel['type'] !== 'hasManyThrough') continue;

                // Measure the chain depth by counting arrows in 'via'
                $depth = substr_count($rel['via'], '→') + 1;

                if ($depth >= 3) {
                    $diagnostics[] = Diagnostic::warning(
                        'eager_depth',
                        $modelName,
                        "{$modelName}::{$rel['method']}() is a {$depth}-level chain ({$rel['via']})",
                        "Consider caching this query result or flattening the relationship. Deep chains can produce huge JOINs on large datasets."
                    );
                }

                if ($depth >= 4) {
                    $diagnostics[] = Diagnostic::error(
                        'eager_depth',
                        $modelName,
                        "{$modelName}::{$rel['method']}() is a critically deep {$depth}-level chain",
                        "This will likely cause severe performance issues at scale. Denormalize the data or use a dedicated query/service instead."
                    );
                }
            }

            // Detect deeply nested eager loads in existing controller files
            $this->scanControllerEagerLoads($modelName, $rels, $diagnostics);
        }

        return $diagnostics;
    }

    private function scanControllerEagerLoads(string $modelName, array $rels, array &$diagnostics): void
    {
        $path = app_path("Http/Controllers/{$modelName}Controller.php");
        if (!file_exists($path)) return;

        $content = file_get_contents($path);

        // Find ->with(['a.b.c.d']) — count dot depth
        preg_match_all('/->with\(\[([^\]]+)\]\)/', $content, $m);
        foreach ($m[1] as $withBlock) {
            preg_match_all("/'([^']+)'/", $withBlock, $strings);
            foreach ($strings[1] as $relation) {
                $depth = substr_count($relation, '.') + 1;
                if ($depth >= 3) {
                    $diagnostics[] = Diagnostic::warning(
                        'eager_depth',
                        $modelName,
                        "{$modelName}Controller uses deep eager load: '{$relation}' ({$depth} levels)",
                        "Consider splitting into separate queries or using lazy loading for rarely-accessed deep relations"
                    );
                }
            }
        }
    }
}

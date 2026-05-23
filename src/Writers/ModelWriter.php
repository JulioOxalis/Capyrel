<?php

namespace Julio\Capyrel\Writers;

use Julio\Capyrel\Generators\RelationMethodGenerator;

class ModelWriter
{
    public function __construct(
        private RelationMethodGenerator $generator,
        private ModelEnhancer           $enhancer,
    ) {}

    /**
     * Inject missing relationship methods AND all model enhancements (casts, scopes, etc.)
     * into an existing model file.
     * Returns total number of things added/enhanced.
     */
    public function write(string $path, array $relationships, array $columns = [], array $indexes = []): int
    {
        if (!file_exists($path)) return 0;

        $content  = file_get_contents($path);
        $existing = $this->existingMethods($content);
        $added    = 0;
        $toInject = '';

        // ── Relationship methods ───────────────────────────────────────────
        foreach ($relationships as $rel) {
            if (empty($rel['method'])) continue;
            if (in_array($rel['method'], $existing)) continue;

            $toInject .= $this->generator->generate($rel);
            $added++;
        }

        if ($toInject) {
            $content = preg_replace('/\n}\s*$/', "\n{$toInject}\n}", $content);
            file_put_contents($path, $content);
        }

        // ── Model enhancements (casts, hidden, scopes, accessors, etc.) ───
        if (!empty($columns)) {
            $enhanced = $this->enhancer->enhance($path, $columns, $relationships, $indexes);
            $added   += $enhanced;
        }

        return $added;
    }

    public function findModelPath(string $modelName): ?string
    {
        $candidates = [
            app_path("Models/{$modelName}.php"),
            app_path("{$modelName}.php"),
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) return $path;
        }

        return null;
    }

    private function existingMethods(string $content): array
    {
        preg_match_all('/public\s+function\s+(\w+)\s*\(/', $content, $matches);
        return $matches[1] ?? [];
    }
}

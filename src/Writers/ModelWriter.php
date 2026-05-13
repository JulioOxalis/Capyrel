<?php

namespace Julio\Capyrel\Writers;

use Julio\Capyrel\Generators\RelationMethodGenerator;

class ModelWriter
{
    public function __construct(private RelationMethodGenerator $generator) {}

    /**
     * Inject missing relationship methods into a model file.
     * Returns the number of methods added.
     */
    public function write(string $path, array $relationships): int
    {
        if (!file_exists($path)) return 0;

        $content  = file_get_contents($path);
        $existing = $this->existingMethods($content);
        $added    = 0;
        $toInject = '';

        foreach ($relationships as $rel) {
            if (empty($rel['method'])) continue;
            if (in_array($rel['method'], $existing)) continue;

            $toInject .= $this->generator->generate($rel);
            $added++;
        }

        if ($added === 0) return 0;

        // Inject before the last closing brace of the class
        $content = preg_replace('/\n}\s*$/', "\n{$toInject}\n}", $content);
        file_put_contents($path, $content);

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

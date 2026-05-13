<?php

namespace Julio\Capyrel\Analyzers;

use Illuminate\Support\Str;

/**
 * X10 FEATURE: Scans all controllers and blade files.
 * If a relationship method is defined in a model but never accessed
 * anywhere in the codebase, flags it as potentially dead code.
 */
class DeadRelationshipAnalyzer
{
    public function analyze(array $relationships): array
    {
        $diagnostics = [];
        $codebase    = $this->readCodebase();

        foreach ($relationships as $modelName => $rels) {
            $modelPath = app_path("Models/{$modelName}.php");
            if (!file_exists($modelPath)) continue;

            $modelContent  = file_get_contents($modelPath);

            foreach ($rels as $rel) {
                if (empty($rel['method'])) continue;

                $method = $rel['method'];

                // Check if this relationship was already defined before capyrel (not a capyrel comment)
                if (!str_contains($modelContent, "// capyrel:") &&
                    !preg_match('/public\s+function\s+' . preg_quote($method, '/') . '\s*\(/', $modelContent)) {
                    // Not written yet — skip dead check
                    continue;
                }

                // Search entire codebase for ->method or ->method()
                $used = str_contains($codebase, "->{$method}")
                     || str_contains($codebase, "with('{$method}')")
                     || str_contains($codebase, "with(\"{$method}\")")
                     || str_contains($codebase, "'{$method}'")
                     || str_contains($codebase, "\"{$method}\"");

                if (!$used) {
                    $diagnostics[] = Diagnostic::info(
                        'dead_relationship',
                        $modelName,
                        "{$modelName}::{$method}() is never referenced in controllers or views",
                        "Consider removing or documenting this relationship if it's truly unused — dead relationships add cognitive overhead"
                    );
                }
            }
        }

        return $diagnostics;
    }

    private function readCodebase(): string
    {
        $content = '';
        $dirs    = [
            app_path('Http/Controllers'),
            resource_path('views'),
            app_path('Livewire'),
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($files as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'blade.php', 'html'])) {
                    $content .= file_get_contents($file->getPathname());
                }
            }
        }

        return $content;
    }
}

<?php

namespace Julio\Capyrel\Analyzers;

use Illuminate\Support\Str;

/**
 * Scans controller files for relationship property access inside foreach loops.
 * Flags potential N+1 query problems.
 */
class N1QueryAnalyzer
{
    public function analyze(array $relationships): array
    {
        $diagnostics    = [];
        $controllerPath = app_path('Http/Controllers');

        if (!is_dir($controllerPath)) return $diagnostics;

        foreach (glob("{$controllerPath}/*.php") as $file) {
            $content  = file_get_contents($file);
            $filename = basename($file);
            $lines    = explode("\n", $content);

            $inLoop        = false;
            $loopVar       = '';
            $loopStartLine = 0;

            foreach ($lines as $lineNum => $line) {
                $real = $lineNum + 1;

                // Detect foreach($collection as $item)
                if (preg_match('/foreach\s*\(\s*\$(\w+)\s+as\s+\$(\w+)\s*\)/', $line, $m)) {
                    $inLoop        = true;
                    $loopVar       = $m[2];
                    $loopStartLine = $real;
                }

                if ($inLoop && str_contains($line, '}') && !str_contains($line, '{')) {
                    $inLoop  = false;
                    $loopVar = '';
                }

                // Inside a loop, look for $loopVar->someRelation
                if ($inLoop && $loopVar) {
                    if (preg_match('/\$' . preg_quote($loopVar, '/') . '->(\w+)/', $line, $m)) {
                        $accessor = $m[1];

                        // Skip if it's a method call (e.g. ->save(), ->update())
                        if (preg_match('/\$' . preg_quote($loopVar, '/') . '->' . preg_quote($accessor, '/') . '\s*\(/', $line)) {
                            continue;
                        }

                        // Skip scalar columns (id, name, email, etc.)
                        if (in_array($accessor, ['id', 'name', 'email', 'title', 'body', 'created_at', 'updated_at', 'deleted_at'])) {
                            continue;
                        }

                        // Check if this looks like a relationship
                        $isRelation = false;
                        foreach ($relationships as $modelName => $rels) {
                            foreach ($rels as $rel) {
                                if ($rel['method'] === $accessor) {
                                    $isRelation = true;
                                    break 2;
                                }
                            }
                        }

                        if ($isRelation) {
                            $diagnostics[] = Diagnostic::warning(
                                'n1_query',
                                '',
                                "{$filename} line {$real}: \${$loopVar}->{$accessor} accessed inside a loop (started line {$loopStartLine})",
                                "Add ->with('{$accessor}') to the query above the foreach to prevent N+1 queries"
                            );
                        }
                    }
                }
            }
        }

        return $diagnostics;
    }
}

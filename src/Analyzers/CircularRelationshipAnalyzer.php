<?php

namespace Julio\Capyrel\Analyzers;

/**
 * Detects genuinely circular relationship chains that would cause infinite
 * eager-loading loops — e.g. Category hasMany SubCategory, SubCategory hasMany Category.
 *
 * IMPORTANT: belongsTo / hasOne are deliberately excluded from the graph.
 * They are always the inverse of a hasMany/hasManyThrough and never cause
 * infinite loops — including them would flag every normal relationship pair.
 * Only downward, collection-producing relationships can create real cycles.
 */
class CircularRelationshipAnalyzer
{
    /** Only these types can produce a genuinely looping eager-load chain. */
    private array $downwardTypes = ['hasMany', 'hasManyThrough', 'belongsToMany', 'hasOneThrough'];

    public function analyze(array $relationships): array
    {
        $diagnostics = [];
        $graph       = $this->buildGraph($relationships);
        $reported    = [];

        foreach (array_keys($graph) as $node) {
            $cycle = $this->dfs($node, $graph, []);

            if ($cycle) {
                // Normalise so A→B→A and B→A→B report only once
                $key = implode('|', $cycle);
                if (isset($reported[$key])) continue;
                $reported[$key] = true;

                $chain = implode(' → ', $cycle);
                $diagnostics[] = Diagnostic::error(
                    'circular_relationship',
                    $node,
                    "Self-referential cycle detected: {$chain}",
                    "Eager-loading this chain recursively will cause an infinite loop. Add a depth guard or use a closure scope: ->with(['children' => fn(\$q) => \$q->limit(3)])"
                );
            }
        }

        return $diagnostics;
    }

    private function buildGraph(array $relationships): array
    {
        $graph = [];
        foreach ($relationships as $model => $rels) {
            $graph[$model] = [];
            foreach ($rels as $rel) {
                // Skip upward navigations (belongsTo, hasOne, morphTo) —
                // they are inverses of downward rels, not cycles.
                if (!empty($rel['related']) && in_array($rel['type'], $this->downwardTypes)) {
                    $graph[$model][] = $rel['related'];
                }
            }
        }
        return $graph;
    }

    private function dfs(string $node, array $graph, array $path): array
    {
        if (in_array($node, $path)) {
            // Return the cycle
            $cycleStart = array_search($node, $path);
            return array_merge(array_slice($path, $cycleStart), [$node]);
        }

        if (!isset($graph[$node])) return [];

        $path[] = $node;

        foreach ($graph[$node] as $neighbour) {
            $cycle = $this->dfs($neighbour, $graph, $path);
            if ($cycle) return $cycle;
        }

        return [];
    }
}

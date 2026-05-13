<?php

namespace Julio\Capyrel\Analyzers;

/**
 * Detects one-sided relationships where the inverse is missing.
 * e.g. User hasMany Post detected, but Post belongsTo User is absent.
 */
class InverseRelationshipAnalyzer
{
    private array $inverseMap = [
        'hasMany'        => 'belongsTo',
        'hasOne'         => 'belongsTo',
        'belongsTo'      => ['hasMany', 'hasOne'],
        'belongsToMany'  => 'belongsToMany',
        'hasManyThrough' => null, // no strict inverse required
        'morphTo'        => null,
        'morphMany'      => null,
    ];

    public function analyze(array $relationships): array
    {
        $diagnostics = [];

        foreach ($relationships as $modelName => $rels) {
            foreach ($rels as $rel) {
                $expectedInverse = $this->inverseMap[$rel['type']] ?? null;
                if (!$expectedInverse || empty($rel['related'])) continue;

                $relatedModel    = $rel['related'];
                $relatedRels     = $relationships[$relatedModel] ?? [];
                $expectedTypes   = (array) $expectedInverse;

                $hasInverse = collect($relatedRels)->contains(
                    fn($r) => in_array($r['type'], $expectedTypes) && $r['related'] === $modelName
                );

                if (!$hasInverse) {
                    $inverseLabel = implode(' or ', $expectedTypes);
                    $diagnostics[] = Diagnostic::info(
                        'inverse_missing',
                        $modelName,
                        "{$modelName} has {$rel['type']}({$relatedModel}) but {$relatedModel} has no {$inverseLabel}({$modelName})",
                        "Capyrel will add the inverse — or add it manually: \$this->{$inverseLabel}({$modelName}::class)"
                    );
                }
            }
        }

        return $diagnostics;
    }
}

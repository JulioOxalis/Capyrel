<?php

namespace Julio\Capyrel\Analyzers;

use Julio\Capyrel\Schema\SchemaAnalyzer;

class DiagnosticsRunner
{
    public function __construct(
        private N1QueryAnalyzer             $n1,
        private MissingIndexAnalyzer        $missingIndex,
        private OrphanForeignKeyAnalyzer    $orphanFk,
        private InverseRelationshipAnalyzer $inverseRel,
        private NamingConflictAnalyzer      $namingConflict,
        private SoftDeleteAnalyzer          $softDelete,
        private EagerLoadDepthAnalyzer      $eagerDepth,
        private CircularRelationshipAnalyzer $circular,
        private CascadeRiskAnalyzer         $cascade,
        private DeadRelationshipAnalyzer    $deadRel,
        private SchemaFillableDriftAnalyzer $fillableDrift,
        private MorphTypeRegistryAnalyzer   $morphRegistry,
    ) {}

    /** Run every analyzer and return all diagnostics sorted by severity. */
    public function run(array $relationships, SchemaAnalyzer $schema): array
    {
        $all = array_merge(
            $this->n1->analyze($relationships),
            $this->missingIndex->analyze($schema),
            $this->orphanFk->analyze($schema),
            $this->inverseRel->analyze($relationships),
            $this->namingConflict->analyze($relationships),
            $this->softDelete->analyze($relationships, $schema),
            $this->eagerDepth->analyze($relationships),
            $this->circular->analyze($relationships),
            $this->cascade->analyze($schema),
            $this->deadRel->analyze($relationships),
            $this->fillableDrift->analyze($schema),
            $this->morphRegistry->analyze($relationships, $schema),
        );

        // Sort: errors first, then warnings, then info
        $order = [Diagnostic::ERROR => 0, Diagnostic::WARNING => 1, Diagnostic::INFO => 2];
        usort($all, fn($a, $b) => ($order[$a->level] ?? 3) <=> ($order[$b->level] ?? 3));

        return $all;
    }
}

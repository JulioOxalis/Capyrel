<?php

namespace Julio\Capyrel\Schema;

use Illuminate\Support\Str;

class RelationshipDetector
{
    public function __construct(private SchemaAnalyzer $schema) {}

    /**
     * Detect all Eloquent relationships from the DB schema.
     *
     * Returns: [ 'ModelName' => [ ['type'=>..., 'related'=>..., 'method'=>..., 'via'=>...], ... ] ]
     */
    public function detect(): array
    {
        $relationships = [];

        foreach ($this->schema->getTables() as $table) {
            if ($this->schema->isPivotTable($table)) continue;
            $relationships[$this->tableToModel($table)] = [];
        }

        $this->detectBelongsTo($relationships);
        $this->detectHasManyAndHasOne($relationships);
        $this->detectBelongsToMany($relationships);
        $this->detectMorphTo($relationships);
        $this->detectHasManyThrough($relationships);

        // Remove models with no relationships
        return array_filter($relationships, fn($rels) => !empty($rels));
    }

    // ─── Phase 1: belongsTo ──────────────────────────────────────────────────

    private function detectBelongsTo(array &$relationships): void
    {
        foreach ($this->schema->getTables() as $table) {
            if ($this->schema->isPivotTable($table)) continue;

            $model   = $this->tableToModel($table);
            $tracked = $this->trackedMethods($relationships[$model] ?? []);

            // FK constraints (most accurate)
            foreach ($this->schema->getForeignKeys($table) as $fk) {
                $relatedTable = $fk['foreign_table'];
                if ($this->schema->isPivotTable($relatedTable)) continue;

                $relatedModel = $this->tableToModel($relatedTable);
                $fkColumn     = $fk['columns'][0];
                $method       = Str::camel(Str::singular($relatedTable));

                if (in_array($method, $tracked)) continue;
                $tracked[] = $method;

                $relationships[$model][] = [
                    'type'        => 'belongsTo',
                    'related'     => $relatedModel,
                    'method'      => $method,
                    'foreign_key' => $fkColumn,
                    'via'         => "{$table}.{$fkColumn}",
                ];
            }

            // Fallback: naming convention (_id suffix, no FK constraint defined)
            foreach ($this->schema->getColumnNames($table) as $col) {
                if (!Str::endsWith($col, '_id') || $col === 'id') continue;

                $guessedSingular = Str::beforeLast($col, '_id');
                $guessedTable    = Str::plural($guessedSingular);
                $method          = Str::camel($guessedSingular);

                if (in_array($method, $tracked)) continue;
                if (!in_array($guessedTable, $this->schema->getTables())) continue;
                if ($this->schema->isPivotTable($guessedTable)) continue;

                $tracked[]             = $method;
                $relationships[$model][] = [
                    'type'        => 'belongsTo',
                    'related'     => $this->tableToModel($guessedTable),
                    'method'      => $method,
                    'foreign_key' => $col,
                    'via'         => "{$table}.{$col} (convention)",
                ];
            }
        }
    }

    // ─── Phase 2: hasMany / hasOne ───────────────────────────────────────────

    private function detectHasManyAndHasOne(array &$relationships): void
    {
        foreach ($this->schema->getTables() as $table) {
            if ($this->schema->isPivotTable($table)) continue;

            $model      = $this->tableToModel($table);
            $tracked    = $this->trackedMethods($relationships[$model] ?? []);
            $pointingTo = $this->schema->getTablesPointingTo($table);

            foreach ($pointingTo as $otherTable) {
                $fkColumn     = Str::singular($table) . '_id';
                $isUnique     = $this->schema->isUniqueColumn($otherTable, $fkColumn);
                $relatedModel = $this->tableToModel($otherTable);

                $method = $isUnique
                    ? Str::camel(Str::singular($otherTable))
                    : Str::camel($otherTable);

                if (in_array($method, $tracked)) continue;
                $tracked[] = $method;

                $relationships[$model][] = [
                    'type'        => $isUnique ? 'hasOne' : 'hasMany',
                    'related'     => $relatedModel,
                    'method'      => $method,
                    'foreign_key' => $fkColumn,
                    'via'         => "{$otherTable}.{$fkColumn}" . ($isUnique ? ' [unique]' : ''),
                ];
            }
        }
    }

    // ─── Phase 3: belongsToMany via pivot ────────────────────────────────────

    private function detectBelongsToMany(array &$relationships): void
    {
        foreach ($this->schema->getTables() as $pivotTable) {
            if (!$this->schema->isPivotTable($pivotTable)) continue;

            $fks = $this->schema->getForeignKeys($pivotTable);

            // Use FK constraints if available, otherwise guess from column names
            if (count($fks) >= 2) {
                $tableA = $fks[0]['foreign_table'];
                $tableB = $fks[1]['foreign_table'];
            } else {
                [$tableA, $tableB] = $this->guessPivotTables($pivotTable);
                if (!$tableA || !$tableB) continue;
            }

            $modelA  = $this->tableToModel($tableA);
            $modelB  = $this->tableToModel($tableB);
            $methodA = Str::camel($tableB);   // on modelA, method is tableB name
            $methodB = Str::camel($tableA);   // on modelB, method is tableA name

            if (!isset($relationships[$modelA])) $relationships[$modelA] = [];
            if (!isset($relationships[$modelB])) $relationships[$modelB] = [];

            $trackedA = $this->trackedMethods($relationships[$modelA]);
            $trackedB = $this->trackedMethods($relationships[$modelB]);

            if (!in_array($methodA, $trackedA)) {
                $relationships[$modelA][] = [
                    'type'    => 'belongsToMany',
                    'related' => $modelB,
                    'method'  => $methodA,
                    'pivot'   => $pivotTable,
                    'via'     => "pivot: {$pivotTable}",
                ];
            }

            if (!in_array($methodB, $trackedB)) {
                $relationships[$modelB][] = [
                    'type'    => 'belongsToMany',
                    'related' => $modelA,
                    'method'  => $methodB,
                    'pivot'   => $pivotTable,
                    'via'     => "pivot: {$pivotTable}",
                ];
            }
        }
    }

    // ─── Phase 4: morphTo ────────────────────────────────────────────────────

    private function detectMorphTo(array &$relationships): void
    {
        foreach ($this->schema->getTables() as $table) {
            if ($this->schema->isPivotTable($table)) continue;

            $model   = $this->tableToModel($table);
            $tracked = $this->trackedMethods($relationships[$model] ?? []);

            foreach ($this->schema->getMorphColumns($table) as $morphName) {
                if (in_array($morphName, $tracked)) continue;

                $relationships[$model][] = [
                    'type'   => 'morphTo',
                    'related' => '',
                    'method' => $morphName,
                    'name'   => $morphName,
                    'via'    => "{$table}.{$morphName}_type / {$morphName}_id",
                ];
            }
        }
    }

    // ─── Phase 5: hasManyThrough ─────────────────────────────────────────────

    private function detectHasManyThrough(array &$relationships): void
    {
        // A hasManyThrough(C, B) if A hasMany B AND B hasMany C
        foreach ($relationships as $modelA => $relsA) {
            $hasManyFromA = collect($relsA)->where('type', 'hasMany')->values();

            foreach ($hasManyFromA as $relAtoB) {
                $modelB = $relAtoB['related'];
                if (!isset($relationships[$modelB])) continue;

                $hasManyFromB = collect($relationships[$modelB])->where('type', 'hasMany')->values();

                foreach ($hasManyFromB as $relBtoC) {
                    $modelC = $relBtoC['related'];
                    if ($modelC === $modelA) continue;

                    $method  = Str::camel(Str::plural(Str::snake($modelC)));
                    $tracked = $this->trackedMethods($relationships[$modelA]);

                    if (in_array($method, $tracked)) continue;

                    $relationships[$modelA][] = [
                        'type'    => 'hasManyThrough',
                        'related' => $modelC,
                        'through' => $modelB,
                        'method'  => $method,
                        'via'     => "{$modelA} → {$modelB} → {$modelC}",
                    ];
                }
            }
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function tableToModel(string $table): string
    {
        return Str::studly(Str::singular($table));
    }

    private function trackedMethods(array $relationships): array
    {
        return collect($relationships)->pluck('method')->toArray();
    }

    private function guessPivotTables(string $pivotTable): array
    {
        $tables = $this->schema->getTables();

        foreach ($tables as $tableA) {
            foreach ($tables as $tableB) {
                if ($tableA === $tableB || $tableA === $pivotTable || $tableB === $pivotTable) continue;

                $singularA = Str::singular($tableA);
                $singularB = Str::singular($tableB);

                if ($pivotTable === "{$singularA}_{$singularB}" || $pivotTable === "{$singularB}_{$singularA}") {
                    return [$tableA, $tableB];
                }
            }
        }

        return [null, null];
    }
}

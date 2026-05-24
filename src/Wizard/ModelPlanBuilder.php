<?php

namespace Julio\Capyrel\Wizard;

use Illuminate\Support\Str;

/**
 * Assembles a complete model plan from an entity name + field specs.
 *
 * Accepts a list of "known entities" (already defined in this wizard session
 * or detected in app/Models/) so that FK resolution and composite-name
 * detection can reference the right tables.
 *
 * Composite-name detection examples:
 *   TeamMember   → team_id (→ teams) + user_id (→ users)
 *   OrderItem    → order_id (→ orders) + item_id OR just order_id
 *   ProjectTask  → project_id (→ projects) + task_id (→ tasks)
 *   PostTag      → post_id (→ posts) + tag_id (→ tags)
 */
class ModelPlanBuilder
{
    public function __construct(
        private FieldInferrer    $inferrer,
        private LaravelAwareness $awareness,
    ) {}

    /**
     * Build a plan.
     *
     * @param  string    $entity        StudlyCase model name
     * @param  string[]  $fieldSpecs    Raw field specs e.g. ['title', 'body:text', 'user_id']
     * @param  bool      $softDeletes
     * @param  string[]  $knownEntities StudlyCase entities already defined this session
     */
    public function build(
        string $entity,
        array  $fieldSpecs,
        bool   $softDeletes    = false,
        array  $knownEntities  = [],
    ): array {
        // Build the known-tables context from known entities + always include users/teams defaults
        $knownTables = $this->entitiesToTables(array_merge(['User'], $knownEntities));
        $inferrer    = $this->inferrer->withKnownTables($knownTables);

        // Auto-inject FK fields for composite names if the user gave no fields
        $fieldSpecs = $this->injectCompositeFields($entity, $fieldSpecs, $knownEntities, $inferrer);

        $fields    = $inferrer->fromArray($fieldSpecs);
        $table     = Str::snake(Str::plural($entity));
        $fillable  = $this->buildFillable($fields);
        $casts     = $this->buildCasts($fields);
        $relations = $this->buildRelations($fields, $inferrer);
        $traits    = $this->buildTraits();

        return [
            'entity'      => $entity,
            'table'       => $table,
            'fillable'    => $fillable,
            'casts'       => $casts,
            'timestamps'  => true,
            'soft_deletes' => $softDeletes,
            'relations'   => $relations,
            'fields'      => $fields,
            'traits'      => $traits,
            'interfaces'  => [],
        ];
    }

    // ── Composite-name detection ──────────────────────────────────────────────

    /**
     * If entity looks like a junction/pivot (TeamMember, OrderItem, PostTag),
     * and the user supplied no FK fields, inject the appropriate foreign keys.
     *
     * @param  string[]   $fieldSpecs
     * @param  string[]   $knownEntities
     * @return string[]   Possibly augmented fieldSpecs
     */
    private function injectCompositeFields(
        string       $entity,
        array        $fieldSpecs,
        array        $knownEntities,
        FieldInferrer $inferrer,
    ): array {
        $parts = $this->splitCamelCase($entity);

        // Only act on 2-part names and only if no FK fields already provided
        if (count($parts) < 2) {
            return $fieldSpecs;
        }

        $existingFks = array_filter($fieldSpecs, fn($s) => str_ends_with(explode(':', $s)[0], '_id'));

        if (!empty($existingFks)) {
            return $fieldSpecs; // user already specified FK fields, trust them
        }

        $injected = [];

        foreach ($parts as $part) {
            $lower = strtolower($part);
            $fkName = $lower . '_id';

            // Resolve where this FK points
            $table = $inferrer->resolveTable($lower);

            // Only inject if it points somewhere meaningful
            // (known entity, users table, or a standard table name)
            if ($table === 'users' ||
                in_array($table, $this->entitiesToTables($knownEntities), true) ||
                in_array(Str::studly(Str::singular($part)), $knownEntities, true)
            ) {
                $injected[] = $fkName;
            }
        }

        if (!empty($injected)) {
            // Prepend the FK fields; keep any non-FK specs the user typed
            $nonFkSpecs = array_filter($fieldSpecs, fn($s) => !str_ends_with(explode(':', $s)[0], '_id'));
            return array_merge($injected, array_values($nonFkSpecs));
        }

        return $fieldSpecs;
    }

    /**
     * Split a StudlyCase name on word boundaries.
     * 'TeamMember' → ['Team', 'Member']
     * 'ProjectTaskAssignment' → ['Project', 'Task', 'Assignment']
     */
    private function splitCamelCase(string $name): array
    {
        $parts = preg_split('/(?<=[a-z])(?=[A-Z])/', $name);
        return array_values(array_filter($parts ?? []));
    }

    /**
     * Convert StudlyCase entity names to snake_plural table names.
     * ['TeamMember', 'Plan'] → ['team_members', 'plans']
     *
     * @param  string[]  $entities
     * @return string[]
     */
    public function entitiesToTables(array $entities): array
    {
        return array_values(array_map(
            fn($e) => Str::snake(Str::plural($e)),
            $entities,
        ));
    }

    // ── Private builders ──────────────────────────────────────────────────────

    private function buildFillable(array $fields): array
    {
        $skip = ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token',
                 'email_verified_at', 'two_factor_secret', 'two_factor_recovery_codes'];

        return array_values(array_filter(
            array_column($fields, 'name'),
            fn($name) => !in_array($name, $skip, true),
        ));
    }

    private function buildCasts(array $fields): array
    {
        $casts = [];

        foreach ($fields as $f) {
            $cast = match ($f['type']) {
                'boolean'               => 'boolean',
                'timestamp', 'datetime' => 'datetime',
                'date'                  => 'date',
                'decimal', 'float'      => 'decimal:2',
                'integer', 'bigInteger' => 'integer',
                'json', 'jsonb'         => 'array',
                default                 => null,
            };

            if ($cast !== null) {
                $casts[$f['name']] = $cast;
            }
        }

        return $casts;
    }

    private function buildRelations(array $fields, FieldInferrer $inferrer): array
    {
        $relations = [];

        foreach ($fields as $f) {
            if ($f['type'] !== 'foreignId') continue;

            $references = $f['references'] ?? $inferrer->resolveTable(str_replace('_id', '', $f['name']));
            $related    = Str::studly(Str::singular($references));

            $relations[] = [
                'type'        => 'belongsTo',
                'related'     => $related,
                'foreign_key' => $f['name'],
            ];
        }

        return $relations;
    }

    private function buildTraits(): array
    {
        $traits = [];

        if ($this->awareness->hasScout()) {
            $traits[] = 'Searchable';
        }

        if ($this->awareness->hasSpatieMedia()) {
            $traits[] = 'InteractsWithMedia';
        }

        return $traits;
    }

    // ── Plan manipulation helpers ─────────────────────────────────────────────

    public function addField(array &$plan, string $fieldSpec): void
    {
        $inferrer = $this->inferrer->withKnownTables(
            $this->entitiesToTables(['User'])
        );

        $defs = $inferrer->fromArray([$fieldSpec]);
        if (empty($defs)) return;

        $def = $defs[0];
        $plan['fields'][] = $def;

        if (!in_array($def['name'], $plan['fillable'], true)) {
            $plan['fillable'][] = $def['name'];
        }

        $cast = match ($def['type']) {
            'boolean'               => 'boolean',
            'timestamp', 'datetime' => 'datetime',
            'date'                  => 'date',
            'decimal', 'float'      => 'decimal:2',
            'integer', 'bigInteger' => 'integer',
            'json', 'jsonb'         => 'array',
            default                 => null,
        };

        if ($cast !== null) {
            $plan['casts'][$def['name']] = $cast;
        }

        if ($def['type'] === 'foreignId') {
            $references = $def['references'] ?? Str::plural(str_replace('_id', '', $def['name']));
            $related    = Str::studly(Str::singular($references));
            $plan['relations'][] = [
                'type'        => 'belongsTo',
                'related'     => $related,
                'foreign_key' => $def['name'],
            ];
        }
    }

    public function removeField(array &$plan, string $fieldName): void
    {
        $plan['fields']    = array_values(array_filter($plan['fields'],    fn($f) => $f['name'] !== $fieldName));
        $plan['fillable']  = array_values(array_filter($plan['fillable'],  fn($n) => $n !== $fieldName));
        unset($plan['casts'][$fieldName]);
        $plan['relations'] = array_values(array_filter($plan['relations'], fn($r) => ($r['foreign_key'] ?? '') !== $fieldName));
    }
}

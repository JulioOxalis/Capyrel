<?php

namespace Julio\Capyrel\Wizard;

use Illuminate\Support\Str;

/**
 * Assembles a complete "model plan" from an entity name and inferred fields.
 *
 * The plan is a plain array — serialisable to JSON, readable by the wizard,
 * consumable by ModelWriter and MigrationWriter.
 *
 * Plan structure:
 * [
 *   'entity'     => 'Post',
 *   'table'      => 'posts',
 *   'fillable'   => ['title', 'body', 'user_id'],
 *   'casts'      => ['published_at' => 'datetime', 'is_featured' => 'boolean', 'meta' => 'array'],
 *   'timestamps' => true,
 *   'softDeletes'=> false,
 *   'relations'  => [
 *     ['type' => 'belongsTo', 'related' => 'User', 'foreign_key' => 'user_id'],
 *   ],
 *   'fields'     => [ ...raw FieldInferrer output... ],
 *   'traits'     => ['Searchable'],   // from LaravelAwareness
 *   'interfaces' => [],
 * ]
 */
class ModelPlanBuilder
{
    public function __construct(
        private FieldInferrer    $inferrer,
        private LaravelAwareness $awareness,
    ) {}

    /**
     * Build a plan from a entity name and raw field specs.
     *
     * @param  string    $entity  StudlyCase model name (e.g. 'BlogPost')
     * @param  string[]  $fieldSpecs  Raw field specs, e.g. ['title', 'body:text', 'user_id']
     * @param  bool      $softDeletes  Whether to add deleted_at / SoftDeletes
     */
    public function build(string $entity, array $fieldSpecs, bool $softDeletes = false): array
    {
        $fields    = $this->inferrer->fromArray($fieldSpecs);
        $table     = Str::snake(Str::plural($entity));
        $fillable  = $this->buildFillable($fields);
        $casts     = $this->buildCasts($fields);
        $relations = $this->buildRelations($fields);
        $traits    = $this->buildTraits($entity);

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

    private function buildRelations(array $fields): array
    {
        $relations = [];

        foreach ($fields as $f) {
            if ($f['type'] !== 'foreignId') continue;

            $references = $f['references'] ?? Str::plural(str_replace('_id', '', $f['name']));
            $related    = Str::studly(Str::singular($references));

            $relations[] = [
                'type'        => 'belongsTo',
                'related'     => $related,
                'foreign_key' => $f['name'],
            ];
        }

        return $relations;
    }

    private function buildTraits(string $entity): array
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

    /**
     * Add a new field spec to an existing plan, re-inferring the field definition.
     */
    public function addField(array &$plan, string $fieldSpec): void
    {
        $defs = $this->inferrer->fromArray([$fieldSpec]);
        if (empty($defs)) return;

        $def  = $defs[0];
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

    /**
     * Remove a field from a plan by name.
     */
    public function removeField(array &$plan, string $fieldName): void
    {
        $plan['fields']   = array_values(array_filter($plan['fields'],   fn($f) => $f['name'] !== $fieldName));
        $plan['fillable'] = array_values(array_filter($plan['fillable'], fn($n) => $n !== $fieldName));
        unset($plan['casts'][$fieldName]);
        $plan['relations'] = array_values(array_filter($plan['relations'], fn($r) => ($r['foreign_key'] ?? '') !== $fieldName));
    }
}

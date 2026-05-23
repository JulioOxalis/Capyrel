<?php

namespace Julio\Capyrel\UI;

use Illuminate\Support\Str;

/**
 * Transforms raw schema data into a neutral UI Contract.
 *
 * The contract describes INTENT only — no HTML, no CSS, no layout decisions.
 * Adapters receive the contract and decide how to render it.
 */
class UiContractBuilder
{
    // Columns that are never shown in list/form/detail views
    private const HIDDEN = ['password', 'remember_token', 'email_verified_at', 'two_factor_secret', 'two_factor_recovery_codes'];

    // Columns excluded from list display (internal / noisy)
    private const LIST_SKIP = ['id', 'updated_at', 'deleted_at'];

    // Columns excluded from create/edit forms
    private const FORM_SKIP = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * Build a UI Contract array from model schema data.
     *
     * @param string $model        Model name, e.g. "Post"
     * @param array  $columns      Column definitions from SchemaAnalyzer::getColumns()
     * @param array  $relationships Relationship definitions from RelationshipDetector::detect()[$model]
     */
    public function build(string $model, array $columns, array $relationships): array
    {
        return [
            'entity'    => $model,
            'meta'      => $this->buildMeta($model, $columns),
            'screens'   => [
                'list'   => $this->buildList($columns, $relationships),
                'create' => $this->buildCreate($columns, $relationships),
                'show'   => $this->buildShow($columns, $relationships),
            ],
            'relations' => $this->buildRelations($relationships),
        ];
    }

    // ── Meta ─────────────────────────────────────────────────────────────────

    private function buildMeta(string $model, array $columns): array
    {
        $colNames = array_column($columns, 'name');

        return [
            'title_field'       => $this->detectTitleField($colNames),
            'description_field' => $this->detectDescriptionField($colNames),
            'icon'              => $this->inferIcon($model),
        ];
    }

    private function detectTitleField(array $colNames): ?string
    {
        foreach (['title', 'name', 'label', 'subject', 'heading', 'username', 'slug'] as $candidate) {
            if (in_array($candidate, $colNames, true)) return $candidate;
        }
        return $colNames[0] ?? null;
    }

    private function detectDescriptionField(array $colNames): ?string
    {
        foreach (['description', 'content', 'body', 'summary', 'excerpt', 'bio', 'notes', 'text'] as $candidate) {
            if (in_array($candidate, $colNames, true)) return $candidate;
        }
        return null;
    }

    private function inferIcon(string $model): string
    {
        $lower = strtolower($model);
        return match (true) {
            str_contains($lower, 'user')    => 'users',
            str_contains($lower, 'post')    => 'document-text',
            str_contains($lower, 'comment') => 'chat-bubble-left',
            str_contains($lower, 'tag')     => 'tag',
            str_contains($lower, 'product') => 'shopping-bag',
            str_contains($lower, 'order')   => 'shopping-cart',
            str_contains($lower, 'invoice') => 'document',
            str_contains($lower, 'role')    => 'shield-check',
            str_contains($lower, 'media')   => 'photo',
            str_contains($lower, 'file')    => 'paper-clip',
            str_contains($lower, 'image')   => 'photo',
            default                         => 'rectangle-stack',
        };
    }

    // ── List screen ───────────────────────────────────────────────────────────

    private function buildList(array $columns, array $relationships): array
    {
        $fields = $this->listFields($columns);
        $relPreview = $this->buildRelationsPreview($relationships);

        return [
            'type'               => 'collection',
            'display'            => 'auto',
            'fields'             => $fields,
            'relations_preview'  => $relPreview,
            'actions'            => ['create', 'bulk_delete'],
        ];
    }

    private function listFields(array $columns): array
    {
        $skip   = array_merge(self::HIDDEN, self::LIST_SKIP);
        $fields = [];

        // Always lead with id for reference
        $fields[] = 'id';

        foreach ($columns as $col) {
            $name = $col['name'] ?? '';
            if (in_array($name, $skip, true)) continue;
            if (str_ends_with($name, '_id')) continue; // FK cols shown via relation preview
            if (in_array($name, $fields, true)) continue;
            $fields[] = $name;
        }

        // Cap at 6 visible columns to keep the list usable
        $textFields = array_slice($fields, 0, 6);

        // Always add created_at if it exists
        $colNames = array_column($columns, 'name');
        if (in_array('created_at', $colNames, true) && !in_array('created_at', $textFields, true)) {
            $textFields[] = 'created_at';
        }

        return $textFields;
    }

    private function buildRelationsPreview(array $relationships): array
    {
        $preview = [];

        foreach ($relationships as $rel) {
            $method = $rel['method'] ?? Str::camel($rel['related']);

            $preview[$method] = match ($rel['type']) {
                'belongsTo'     => 'avatar',
                'hasMany'       => 'count',
                'belongsToMany' => 'count',
                'hasOne'        => 'badge',
                default         => 'count',
            };
        }

        return $preview;
    }

    // ── Create screen ─────────────────────────────────────────────────────────

    private function buildCreate(array $columns, array $relationships): array
    {
        $fields    = $this->formFields($columns);
        $relations = $this->buildFormRelations($relationships);

        return [
            'type'      => 'form',
            'fields'    => $fields,
            'relations' => $relations,
        ];
    }

    private function formFields(array $columns): array
    {
        $skip   = array_merge(self::HIDDEN, self::FORM_SKIP);
        $fields = [];

        foreach ($columns as $col) {
            $name = $col['name'] ?? '';
            if (in_array($name, $skip, true)) continue;
            $fields[] = $name;
        }

        return $fields;
    }

    private function buildFormRelations(array $relationships): array
    {
        $relations = [];

        foreach ($relationships as $rel) {
            $method = $rel['method'] ?? Str::camel($rel['related']);

            $ui = match ($rel['type']) {
                'belongsTo'     => 'select',
                'belongsToMany' => 'multi_select',
                'hasMany'       => null, // not editable in create form
                'hasOne'        => 'form_section',
                default         => null,
            };

            if ($ui !== null) {
                $relations[$method] = $ui;
            }
        }

        return $relations;
    }

    // ── Show screen ───────────────────────────────────────────────────────────

    private function buildShow(array $columns, array $relationships): array
    {
        $hasActivity  = $this->hasTimestamps($columns);
        $hasRelations = !empty($relationships);

        $sections = ['main'];
        if ($hasRelations) $sections[] = 'relations';
        if ($hasActivity)  $sections[] = 'activity';

        return [
            'type'      => 'detail',
            'sections'  => $sections,
            'relations' => $this->buildShowRelations($relationships),
            'actions'   => $this->buildShowActions($relationships),
        ];
    }

    private function buildShowRelations(array $relationships): array
    {
        $relations = [];

        foreach ($relationships as $rel) {
            $method = $rel['method'] ?? Str::camel($rel['related']);

            $ui = match ($rel['type']) {
                'hasMany'       => 'list',
                'belongsToMany' => 'chips',
                'belongsTo'     => 'card',
                'hasOne'        => 'card',
                default         => 'list',
            };

            $relations[$method] = $ui;
        }

        return $relations;
    }

    private function buildShowActions(array $relationships): array
    {
        $actions = ['edit', 'delete'];

        foreach ($relationships as $rel) {
            $lower = strtolower($rel['related'] ?? '');

            if (str_contains($lower, 'comment')) $actions[] = 'comment';
            if (str_contains($lower, 'like') || str_contains($lower, 'reaction')) $actions[] = 'like';
        }

        return array_unique($actions);
    }

    // ── Top-level relations map ───────────────────────────────────────────────

    private function buildRelations(array $relationships): array
    {
        $map = [];

        foreach ($relationships as $rel) {
            $method = $rel['method'] ?? Str::camel($rel['related']);

            $ui = match ($rel['type']) {
                'belongsTo'     => 'avatar_card',
                'hasMany'       => 'thread',
                'belongsToMany' => 'chips',
                'hasOne'        => 'card',
                default         => 'list',
            };

            $map[$method] = [
                'type' => $rel['type'],
                'ui'   => $ui,
            ];
        }

        return $map;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function hasTimestamps(array $columns): bool
    {
        $names = array_column($columns, 'name');
        return in_array('created_at', $names, true) || in_array('updated_at', $names, true);
    }
}

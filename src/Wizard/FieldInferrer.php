<?php

namespace Julio\Capyrel\Wizard;

use Illuminate\Support\Str;

/**
 * Infers typed field definitions from column names or a comma-separated string.
 *
 * Input examples:
 *   "title, body, published_at, is_featured, price, user_id"
 *   ['title', 'body:text', 'price:decimal', 'is_active:boolean']
 *
 * Output (one entry per field):
 *   [
 *     ['name' => 'title',        'type' => 'string',    'nullable' => false, 'unique' => false, 'default' => null],
 *     ['name' => 'body',         'type' => 'text',      'nullable' => true,  'unique' => false, 'default' => null],
 *     ['name' => 'published_at', 'type' => 'timestamp', 'nullable' => true,  'unique' => false, 'default' => null],
 *     ['name' => 'is_featured',  'type' => 'boolean',   'nullable' => false, 'unique' => false, 'default' => false],
 *     ['name' => 'price',        'type' => 'decimal',   'nullable' => false, 'unique' => false, 'default' => '0.00', 'precision' => 10, 'scale' => 2],
 *     ['name' => 'user_id',      'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null,  'references' => 'users'],
 *   ]
 */
class FieldInferrer
{
    /**
     * Parse and infer from a comma-separated string.
     *
     * @return array<int, array>
     */
    public function fromString(string $input): array
    {
        $parts = preg_split('/[,;]+/', $input);
        return $this->fromArray(array_map('trim', array_filter($parts)));
    }

    /**
     * Infer types from an array of field specs.
     * Each item is either "name" or "name:type" or "name:type:modifier".
     *
     * @param  string[]  $fields
     * @return array<int, array>
     */
    public function fromArray(array $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            if (empty($field)) continue;

            $parts    = explode(':', $field, 3);
            $name     = trim($parts[0]);
            $explicit = isset($parts[1]) ? strtolower(trim($parts[1])) : null;
            $modifier = isset($parts[2]) ? strtolower(trim($parts[2])) : null;

            if (empty($name)) continue;

            $definition = $explicit
                ? $this->withExplicitType($name, $explicit, $modifier)
                : $this->infer($name);

            $result[] = $definition;
        }

        return $result;
    }

    /**
     * Infer a single field definition purely from its column name.
     */
    public function infer(string $name): array
    {
        $lower = strtolower($name);

        // ── Foreign keys ──────────────────────────────────────────────────────
        if (str_ends_with($lower, '_id')) {
            $relation = Str::plural(str_replace('_id', '', $lower));
            return $this->make($name, 'foreignId', false, false, null, ['references' => $relation]);
        }

        // ── Timestamps ────────────────────────────────────────────────────────
        if (in_array($lower, ['created_at', 'updated_at', 'deleted_at'])) {
            return $this->make($name, 'timestamp', true);
        }

        if (str_ends_with($lower, '_at') || str_ends_with($lower, '_date') || str_ends_with($lower, '_on')) {
            return $this->make($name, 'timestamp', true);
        }

        // ── Booleans ──────────────────────────────────────────────────────────
        if (str_starts_with($lower, 'is_') || str_starts_with($lower, 'has_') ||
            str_starts_with($lower, 'can_') || str_starts_with($lower, 'should_') ||
            in_array($lower, ['active', 'enabled', 'verified', 'published', 'featured', 'approved'])) {
            return $this->make($name, 'boolean', false, false, false);
        }

        // ── Decimal/money ─────────────────────────────────────────────────────
        if (in_array($lower, ['price', 'cost', 'amount', 'total', 'subtotal', 'tax', 'discount', 'fee', 'balance', 'salary', 'revenue']) ||
            str_ends_with($lower, '_price') || str_ends_with($lower, '_amount') || str_ends_with($lower, '_cost')) {
            return $this->make($name, 'decimal', false, false, '0.00', ['precision' => 10, 'scale' => 2]);
        }

        // ── Integer ───────────────────────────────────────────────────────────
        if (in_array($lower, ['count', 'quantity', 'qty', 'stock', 'views', 'likes', 'votes', 'rating', 'rank', 'position', 'order', 'sort', 'priority']) ||
            str_ends_with($lower, '_count') || str_ends_with($lower, '_qty') || str_ends_with($lower, '_number')) {
            return $this->make($name, 'integer', false, false, 0);
        }

        // ── Long text / JSON ──────────────────────────────────────────────────
        if (in_array($lower, ['body', 'content', 'description', 'summary', 'notes', 'bio', 'details', 'message', 'text', 'html', 'markdown'])) {
            return $this->make($name, 'text', true);
        }

        if (in_array($lower, ['meta', 'settings', 'options', 'config', 'data', 'payload', 'attributes', 'extra', 'properties'])) {
            return $this->make($name, 'json', true);
        }

        // ── Email ─────────────────────────────────────────────────────────────
        if (str_contains($lower, 'email')) {
            return $this->make($name, 'string', false, true);
        }

        // ── URL / path ────────────────────────────────────────────────────────
        if (str_contains($lower, 'url') || str_contains($lower, 'link') || str_ends_with($lower, '_path')) {
            return $this->make($name, 'string', true);
        }

        // ── Image / file ──────────────────────────────────────────────────────
        if (str_contains($lower, 'image') || str_contains($lower, 'photo') ||
            str_contains($lower, 'avatar') || str_contains($lower, 'thumbnail') ||
            str_contains($lower, 'cover') || str_contains($lower, 'attachment') ||
            str_ends_with($lower, '_file') || str_ends_with($lower, '_logo')) {
            return $this->make($name, 'string', true);
        }

        // ── UUID / ULID ───────────────────────────────────────────────────────
        if (str_ends_with($lower, '_uuid') || $lower === 'uuid') {
            return $this->make($name, 'uuid', false, true);
        }

        // ── Enum-like ─────────────────────────────────────────────────────────
        if (in_array($lower, ['status', 'type', 'role', 'gender', 'category', 'level', 'state'])) {
            return $this->make($name, 'string', false, false, null, ['hint' => 'Consider using an Enum cast']);
        }

        // ── Slug ─────────────────────────────────────────────────────────────
        if ($lower === 'slug' || str_ends_with($lower, '_slug')) {
            return $this->make($name, 'string', false, true);
        }

        // ── Token / secret ────────────────────────────────────────────────────
        if (str_contains($lower, 'token') || str_contains($lower, 'secret') || str_contains($lower, 'password')) {
            return $this->make($name, 'string', true);
        }

        // ── Colour ───────────────────────────────────────────────────────────
        if (str_contains($lower, 'color') || str_contains($lower, 'colour')) {
            return $this->make($name, 'string', true);
        }

        // ── Phone ─────────────────────────────────────────────────────────────
        if (str_contains($lower, 'phone') || str_contains($lower, 'mobile') || str_contains($lower, 'fax')) {
            return $this->make($name, 'string', true);
        }

        // Default → string
        return $this->make($name, 'string', false);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function withExplicitType(string $name, string $type, ?string $modifier): array
    {
        $nullable = $modifier === 'null' || $modifier === 'nullable';
        $unique   = $modifier === 'unique';

        $extra = match ($type) {
            'decimal', 'float' => ['precision' => 10, 'scale' => 2],
            'foreignId'        => ['references' => Str::plural(str_replace('_id', '', strtolower($name)))],
            default            => [],
        };

        return $this->make($name, $type, $nullable, $unique, null, $extra);
    }

    private function make(
        string  $name,
        string  $type,
        bool    $nullable = false,
        bool    $unique   = false,
        mixed   $default  = null,
        array   $extra    = [],
    ): array {
        return array_filter([
            'name'     => $name,
            'type'     => $type,
            'nullable' => $nullable,
            'unique'   => $unique,
            'default'  => $default,
        ], fn($v) => $v !== null) + $extra;
    }
}

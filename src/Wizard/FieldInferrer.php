<?php

namespace Julio\Capyrel\Wizard;

use Illuminate\Support\Str;

/**
 * Infers typed field definitions from column names or comma-separated strings.
 *
 * Supports a "known tables" context so that FKs like team_id correctly
 * reference `teams` when Team was defined earlier in the same wizard session,
 * rather than blindly pluralizing.
 */
class FieldInferrer
{
    /**
     * FK column prefixes that are aliases for the users table.
     * e.g. owner_id, author_id, creator_id → references users, not owners/authors/creators.
     */
    public const USER_ALIASES = [
        'owner', 'author', 'creator', 'editor', 'updater', 'deleter',
        'sender', 'receiver', 'recipient', 'approver', 'reviewer',
        'assigned', 'assignee', 'reporter', 'moderator', 'manager',
        'publisher', 'subscriber', 'inviter', 'invitee', 'operator',
        'member', 'participant', 'attendee', 'follower', 'contact',
    ];

    /** Tables confirmed to exist in this session or in the DB, used for FK resolution. */
    private array $knownTables = [];

    // ── Context ───────────────────────────────────────────────────────────────

    /**
     * Set the tables known to exist so FK resolution can use them.
     * Pass snake_plural table names, e.g. ['users', 'teams', 'plans'].
     */
    public function withKnownTables(array $tables): static
    {
        $clone              = clone $this;
        $clone->knownTables = array_map('strtolower', $tables);
        return $clone;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public function fromString(string $input): array
    {
        $parts = preg_split('/[,;]+/', $input);
        return $this->fromArray(array_map('trim', array_filter($parts)));
    }

    /**
     * @param  string[]  $fields  e.g. ['title', 'body:text', 'user_id', 'team_id']
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
     * Infer a single field definition from its column name.
     */
    public function infer(string $name): array
    {
        $lower = strtolower($name);

        // ── Foreign keys ──────────────────────────────────────────────────────
        if (str_ends_with($lower, '_id')) {
            $prefix   = substr($lower, 0, -3); // strip _id
            $table    = $this->resolveTable($prefix);
            return $this->make($name, 'foreignId', false, false, null, ['references' => $table]);
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

        // ── Colour / phone ────────────────────────────────────────────────────
        if (str_contains($lower, 'color') || str_contains($lower, 'colour')) {
            return $this->make($name, 'string', true);
        }
        if (str_contains($lower, 'phone') || str_contains($lower, 'mobile') || str_contains($lower, 'fax')) {
            return $this->make($name, 'string', true);
        }

        return $this->make($name, 'string', false);
    }

    // ── FK resolution ─────────────────────────────────────────────────────────

    /**
     * Resolve the correct table name for a FK prefix.
     *
     * Priority:
     *   1. User-alias (owner, author…) → users
     *   2. Known tables from this session (exact snake_plural match)
     *   3. Simple pluralisation fallback
     */
    public function resolveTable(string $prefix): string
    {
        $lower = strtolower($prefix);

        // 1. User aliases always → users
        if (in_array($lower, self::USER_ALIASES, true)) {
            return 'users';
        }

        // 2. Check known tables — try plural then singular match
        $plural = Str::plural($lower);

        if (in_array($plural, $this->knownTables, true)) {
            return $plural;
        }

        // Try snake_case variant (e.g. team_member → team_members)
        $snakePlural = Str::snake($plural);
        if (in_array($snakePlural, $this->knownTables, true)) {
            return $snakePlural;
        }

        // 3. Fallback: simple pluralise
        return $plural;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function withExplicitType(string $name, string $type, ?string $modifier): array
    {
        $nullable = $modifier === 'null' || $modifier === 'nullable';
        $unique   = $modifier === 'unique';

        $extra = match ($type) {
            'decimal', 'float' => ['precision' => 10, 'scale' => 2],
            'foreignId'        => ['references' => $this->resolveTable(str_replace('_id', '', strtolower($name)))],
            default            => [],
        };

        return $this->make($name, $type, $nullable, $unique, null, $extra);
    }

    private function make(
        string $name,
        string $type,
        bool   $nullable = false,
        bool   $unique   = false,
        mixed  $default  = null,
        array  $extra    = [],
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

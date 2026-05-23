<?php

namespace Julio\Capyrel\Writers;

use Illuminate\Support\Str;
use Julio\Capyrel\Detectors\ColumnTypeDetector;
use Julio\Capyrel\Detectors\UploadColumnDetector;

/**
 * Enhances existing model files with production-grade features:
 *   M1  Auto-casts ($casts array from column types)
 *   M2  Hidden fields ($hidden for passwords/tokens/secrets)
 *   M3  File URL accessors ($appends + Attribute accessors for storage paths)
 *   M4  Query scopes (status, published, archived, byUser, etc.)
 *   M5  Route key name override (when slug/handle column exists)
 *   M6  $touches (for belongsTo parent models)
 *   M10 Audit column auto-fill (created_by, updated_by via booted hooks)
 *   M12 Multitenancy global scope (team_id / tenant_id / org_id)
 *
 * All injections are idempotent — won't add something twice.
 */
class ModelEnhancer
{
    private array $systemColumns = [
        'id', 'created_at', 'updated_at', 'deleted_at',
        'remember_token', 'email_verified_at',
    ];

    private array $sensitiveColumns = [
        'password', 'remember_token', 'two_factor_secret',
        'two_factor_recovery_codes', 'api_token', 'access_token',
        'secret_key', 'private_key', 'stripe_token', 'paypal_token',
    ];

    private array $auditColumns = [
        'created_by', 'updated_by', 'deleted_by',
    ];

    private array $tenantColumns = [
        'team_id', 'tenant_id', 'organization_id', 'org_id', 'account_id',
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Enhance an existing model file.
     * Returns count of features injected.
     */
    public function enhance(string $path, array $columns, array $relationships, array $indexes = []): int
    {
        if (!file_exists($path)) return 0;

        $content  = file_get_contents($path);
        $original = $content;
        $methods  = $this->existingMethods($content);
        $props    = $this->existingProperties($content);
        $injected = 0;

        // ── M1: $casts ──────────────────────────────────────────────────────
        if (!in_array('casts', $props)) {
            $casts = $this->buildCasts($columns);
            if ($casts) {
                $content  = $this->injectProperty($content, $casts);
                $injected++;
            }
        }

        // ── M2: $hidden ─────────────────────────────────────────────────────
        if (!in_array('hidden', $props)) {
            $hidden = $this->buildHidden($columns);
            if ($hidden) {
                $content  = $this->injectProperty($content, $hidden);
                $injected++;
            }
        }

        // ── M3: $appends + URL accessors ────────────────────────────────────
        $uploadCols = UploadColumnDetector::collectFrom($columns);
        if (!empty($uploadCols) && !in_array('appends', $props)) {
            $appends = $this->buildAppends($uploadCols);
            $content = $this->injectProperty($content, $appends);

            foreach ($uploadCols as $col) {
                $accessorName = Str::camel($col) . 'UrlAttribute';
                if (!in_array('get' . Str::studly($col) . 'Url', $methods) && !str_contains($content, "{$col}_url")) {
                    $accessor = $this->buildFileUrlAccessor($col);
                    $content  = $this->injectMethod($content, $accessor);
                }
            }
            $injected++;
        }

        // ── M4: Scopes ──────────────────────────────────────────────────────
        $newScopes = $this->buildScopes($columns, $methods);
        if ($newScopes) {
            $content  = $this->injectMethod($content, $newScopes);
            $injected++;
        }

        // ── M5: Route key (slug/handle) ─────────────────────────────────────
        $slugCol = $this->findSlugColumn($columns);
        if ($slugCol && !in_array('getRouteKeyName', $methods)) {
            $content  = $this->injectMethod($content, $this->buildRouteKeyName($slugCol));
            $injected++;
        }

        // ── M6: $touches ────────────────────────────────────────────────────
        $parentRels = collect($relationships)->where('type', 'belongsTo')->values()->toArray();
        if (!empty($parentRels) && !in_array('touches', $props)) {
            $touches = $this->buildTouches($parentRels);
            if ($touches) {
                $content  = $this->injectProperty($content, $touches);
                $injected++;
            }
        }

        // ── M10: Audit columns (booted hooks) ───────────────────────────────
        $auditCols = array_intersect(array_column($columns, 'name'), $this->auditColumns);
        if (!empty($auditCols) && !in_array('booted', $methods)) {
            $content  = $this->injectMethod($content, $this->buildBootedAudit($auditCols));
            $injected++;
        }

        // ── M12: Multitenancy global scope ──────────────────────────────────
        $tenantCol = $this->findTenantColumn($columns);
        if ($tenantCol && !str_contains($content, 'GlobalScope') && !str_contains($content, 'addGlobalScope')) {
            $content  = $this->injectMethod($content, $this->buildMultitenancyScope($tenantCol));
            // Add the use statement for GlobalScope
            if (!str_contains($content, 'use Illuminate\Database\Eloquent\Builder;')) {
                $content = $this->addUseStatement($content, 'Illuminate\Database\Eloquent\Builder');
            }
            $injected++;
        }

        // ── M3b: Add Storage facade if needed ───────────────────────────────
        if (!empty($uploadCols) && !str_contains($content, 'use Illuminate\Support\Facades\Storage;')) {
            $content = $this->addUseStatement($content, 'Illuminate\Support\Facades\Storage');
        }

        // ── M3c: Add Attribute import if using new-style accessors ──────────
        if (!empty($uploadCols) && !str_contains($content, 'use Illuminate\Database\Eloquent\Casts\Attribute;')) {
            $content = $this->addUseStatement($content, 'Illuminate\Database\Eloquent\Casts\Attribute');
        }

        if ($content !== $original) {
            file_put_contents($path, $content);
        }

        return $injected;
    }

    // ── M1: Casts builder ─────────────────────────────────────────────────────

    private function buildCasts(array $columns): string
    {
        $lines  = [];
        $skip   = ['id', '_id', 'remember_token', 'password'];

        foreach ($columns as $col) {
            $name = $col['name'];
            $type = strtolower($col['type_name'] ?? 'string');

            if (in_array($name, $skip)) continue;

            $cast = $this->castForColumn($name, $type, $col);
            if ($cast) {
                $lines[] = "        '{$name}' => {$cast},";
            }
        }

        if (empty($lines)) return '';

        $body = implode("\n", $lines);
        return <<<PHP

    protected \$casts = [
{$body}
    ];
PHP;
    }

    private function castForColumn(string $name, string $type, array $col): string
    {
        if (str_ends_with($name, '_at') || in_array($type, ['datetime', 'timestamp'])) {
            return "'datetime'";
        }
        if ($type === 'date') {
            return "'date'";
        }
        if (in_array($type, ['boolean', 'bool', 'tinyint']) && str_starts_with($name, 'is_')) {
            return "'boolean'";
        }
        if (in_array($type, ['json', 'jsonb'])) {
            return "'array'";
        }
        if ($type === 'decimal' || $type === 'numeric') {
            $scale = $col['scale'] ?? 2;
            return "'decimal:{$scale}'";
        }
        if ($type === 'float' || $type === 'double') {
            return "'float'";
        }
        if ($type === 'enum') {
            // Try to find a matching PHP Enum class
            $enumClass = 'App\\Enums\\' . Str::studly($name);
            return "\\{$enumClass}::class // if App\\Enums\\" . Str::studly($name) . " exists";
        }
        return '';
    }

    // ── M2: Hidden builder ────────────────────────────────────────────────────

    private function buildHidden(array $columns): string
    {
        $names = array_column($columns, 'name');
        $hidden = array_intersect($names, $this->sensitiveColumns);

        if (empty($hidden)) return '';

        $items = implode(",\n        ", array_map(fn($h) => "'{$h}'", $hidden));

        return <<<PHP

    protected \$hidden = [
        {$items},
    ];
PHP;
    }

    // ── M3: File URL accessors ────────────────────────────────────────────────

    private function buildAppends(array $uploadCols): string
    {
        $items = implode(",\n        ", array_map(fn($c) => "'{$c}_url'", $uploadCols));

        return <<<PHP

    protected \$appends = [
        {$items},
    ];
PHP;
    }

    private function buildFileUrlAccessor(string $col): string
    {
        $methodName = Str::studly($col) . 'Url';
        $disk       = config('capyrel.storage_disk', 'public');

        return <<<PHP

    /**
     * Full URL for the {$col} file. Returns null when not set.
     * Access via: \$model->{$col}_url
     */
    protected function {$methodName}(): Attribute
    {
        return Attribute::make(
            get: fn() => \$this->{$col} ? Storage::disk('{$disk}')->url(\$this->{$col}) : null,
        );
    }
PHP;
    }

    // ── M4: Scopes builder ────────────────────────────────────────────────────

    private function buildScopes(array $columns, array $existing): string
    {
        $names  = array_column($columns, 'name');
        $scopes = [];

        // Status/state scope
        if (in_array('status', $names) && !in_array('scopeByStatus', $existing)) {
            $scopes[] = <<<PHP

    public function scopeByStatus(\$query, string \$status): \$query
    {
        return \$query->where('status', \$status);
    }

    public function scopeActive(\$query): \$query
    {
        return \$query->where('status', 'active');
    }
PHP;
        }

        // published_at scope
        if (in_array('published_at', $names) && !in_array('scopePublished', $existing)) {
            $scopes[] = <<<PHP

    public function scopePublished(\$query): \$query
    {
        return \$query->whereNotNull('published_at')
                     ->where('published_at', '<=', now());
    }

    public function scopeDraft(\$query): \$query
    {
        return \$query->whereNull('published_at');
    }
PHP;
        }

        // archived_at scope
        if (in_array('archived_at', $names) && !in_array('scopeArchived', $existing)) {
            $scopes[] = <<<PHP

    public function scopeArchived(\$query): \$query
    {
        return \$query->whereNotNull('archived_at');
    }

    public function scopeLive(\$query): \$query
    {
        return \$query->whereNull('archived_at');
    }
PHP;
        }

        // user_id scope
        if (in_array('user_id', $names) && !in_array('scopeForUser', $existing)) {
            $scopes[] = <<<PHP

    public function scopeForUser(\$query, int \$userId): \$query
    {
        return \$query->where('user_id', \$userId);
    }
PHP;
        }

        // is_featured / is_pinned / is_active booleans
        foreach (['is_featured', 'is_pinned', 'is_active', 'is_enabled', 'is_visible'] as $boolCol) {
            if (in_array($boolCol, $names)) {
                $scopeName = 'scope' . Str::studly($boolCol);
                $shortName = Str::studly(Str::after($boolCol, 'is_'));
                if (!in_array('scope' . $shortName, $existing)) {
                    $scopes[] = <<<PHP

    public function scope{$shortName}(\$query): \$query
    {
        return \$query->where('{$boolCol}', true);
    }
PHP;
                }
            }
        }

        // sort_order / position / rank — ordered scope
        foreach (['sort_order', 'position', 'rank', 'order'] as $orderCol) {
            if (in_array($orderCol, $names) && !in_array('scopeOrdered', $existing)) {
                $scopes[] = <<<PHP

    public function scopeOrdered(\$query, string \$direction = 'asc'): \$query
    {
        return \$query->orderBy('{$orderCol}', \$direction);
    }
PHP;
                break;
            }
        }

        // lat/lng — nearby scope using Haversine
        if (in_array('latitude', $names) && in_array('longitude', $names) && !in_array('scopeNearby', $existing)) {
            $scopes[] = <<<PHP

    /**
     * Find records within \$radiusKm kilometres of (\$lat, \$lng).
     * Uses the Haversine formula. Adds 'distance' column to results.
     */
    public function scopeNearby(\$query, float \$lat, float \$lng, float \$radiusKm = 10): \$query
    {
        return \$query->selectRaw(
            '*, (6371 * acos(cos(radians(?)) * cos(radians(latitude))
             * cos(radians(longitude) - radians(?))
             + sin(radians(?)) * sin(radians(latitude)))) AS distance',
            [\$lat, \$lng, \$lat]
        )
        ->having('distance', '<', \$radiusKm)
        ->orderBy('distance');
    }
PHP;
        }

        // recent — convenience scope
        if (in_array('created_at', $names) && !in_array('scopeRecent', $existing)) {
            $scopes[] = <<<PHP

    public function scopeRecent(\$query, int \$days = 30): \$query
    {
        return \$query->where('created_at', '>=', now()->subDays(\$days));
    }
PHP;
        }

        return implode('', $scopes);
    }

    // ── M5: Route key ─────────────────────────────────────────────────────────

    private function buildRouteKeyName(string $col): string
    {
        return <<<PHP

    public function getRouteKeyName(): string
    {
        return '{$col}';
    }
PHP;
    }

    private function findSlugColumn(array $columns): ?string
    {
        $names = array_column($columns, 'name');
        foreach (['slug', 'handle', 'permalink', 'url_key'] as $candidate) {
            if (in_array($candidate, $names)) return $candidate;
        }
        return null;
    }

    // ── M6: $touches builder ──────────────────────────────────────────────────

    private function buildTouches(array $parentRels): string
    {
        if (empty($parentRels)) return '';

        $items = implode(",\n        ", array_map(fn($r) => "'{$r['method']}'", $parentRels));

        return <<<PHP

    /** Auto-update parent timestamps when this model changes. */
    protected \$touches = [
        {$items},
    ];
PHP;
    }

    // ── M10: Audit column booted hooks ────────────────────────────────────────

    private function buildBootedAudit(array $auditCols): string
    {
        $creating = '';
        $updating = '';
        $deleting = '';

        foreach ($auditCols as $col) {
            if ($col === 'created_by') {
                $creating .= "\n            static::creating(fn(\$m) => \$m->{$col} ??= auth()->id());";
            }
            if ($col === 'updated_by') {
                $updating .= "\n            static::updating(fn(\$m) => \$m->{$col} = auth()->id());";
            }
            if ($col === 'deleted_by') {
                $deleting .= "\n            static::deleting(fn(\$m) => \$m->forceFill(['{$col}' => auth()->id()])->saveQuietly());";
            }
        }

        $hooks = $creating . $updating . $deleting;

        return <<<PHP

    protected static function booted(): void
    {
        // Auto-fill audit columns from the authenticated user
{$hooks}
    }
PHP;
    }

    // ── M12: Multitenancy global scope ────────────────────────────────────────

    private function findTenantColumn(array $columns): ?string
    {
        $names = array_column($columns, 'name');
        foreach ($this->tenantColumns as $candidate) {
            if (in_array($candidate, $names)) return $candidate;
        }
        return null;
    }

    private function buildMultitenancyScope(string $tenantCol): string
    {
        return <<<PHP

    /**
     * Global scope: automatically filter by the authenticated user's {$tenantCol}.
     * Prevents cross-tenant data leaks at the model layer.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('{$tenantCol}', function (Builder \$builder) {
            if (auth()->check() && isset(auth()->user()->{$tenantCol})) {
                \$builder->where(static::qualifyColumn('{$tenantCol}'), auth()->user()->{$tenantCol});
            }
        });
    }
PHP;
    }

    // ── Injection helpers ─────────────────────────────────────────────────────

    /** Inject a property declaration before the first public/protected method. */
    private function injectProperty(string $content, string $block): string
    {
        // Insert before the first `public function` or `protected function`
        return preg_replace(
            '/(\n    (?:public|protected) function )/m',
            "\n{$block}\n$1",
            $content,
            1
        ) ?? $content;
    }

    /** Inject a method before the last closing brace of the class. */
    private function injectMethod(string $content, string $block): string
    {
        return preg_replace('/\n}\s*$/', "\n{$block}\n}", $content) ?? $content;
    }

    /** Add a use statement after the last existing use line. */
    private function addUseStatement(string $content, string $class): string
    {
        $useStatement = "use {$class};";
        if (str_contains($content, $useStatement)) return $content;

        // Add after the last 'use ...' line
        return preg_replace(
            '/(use [^;]+;\n)(?!use )/',
            "$1{$useStatement}\n",
            $content,
            1
        ) ?? ($content . "\n{$useStatement}");
    }

    private function existingMethods(string $content): array
    {
        preg_match_all('/(?:public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\(/', $content, $m);
        return $m[1] ?? [];
    }

    private function existingProperties(string $content): array
    {
        preg_match_all('/(?:public|protected|private)\s+(?:static\s+)?\\\$(\w+)\s*[=;]/', $content, $m);
        return $m[1] ?? [];
    }
}

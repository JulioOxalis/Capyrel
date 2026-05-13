<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Schema\SchemaAnalyzer;

class OptimizeCommand extends Command
{
    protected $signature = 'model:optimize
                            {model?         : Optimize a specific model only}
                            {--connection=  : Database connection to use}
                            {--dry-run      : Preview without writing}
                            {--force        : Skip confirmations}';

    protected $description = 'Add missing $fillable, $casts, $hidden, and named scopes to existing models';

    public function __construct(private SchemaAnalyzer $analyzer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $connection = $this->option('connection') ?? '';

        try {
            $this->analyzer->analyze($connection);
        } catch (\Throwable $e) {
            $this->error("Cannot read schema: {$e->getMessage()}");
            return self::FAILURE;
        }

        $target  = $this->argument('model');
        $skip    = ['migrations', 'cache', 'sessions', 'jobs', 'failed_jobs', 'job_batches', 'password_reset_tokens', 'cache_locks'];
        $updated = 0;

        $this->line('');
        $this->line('  <fg=cyan;options=bold>Optimizing Models...</>');
        $this->line('');

        foreach ($this->analyzer->getTables() as $table) {
            if (in_array($table, $skip)) continue;
            if ($this->analyzer->isPivotTable($table)) continue;

            $modelName = Str::studly(Str::singular($table));
            if ($target && strtolower($modelName) !== strtolower($target)) continue;

            $modelPath = app_path("Models/{$modelName}.php");
            if (!file_exists($modelPath)) continue;

            $columns = $this->analyzer->getColumns($table);
            if (empty($columns)) continue;

            $changes = $this->analyzeModel($modelPath, $columns);
            if (empty($changes)) {
                $this->line("  <fg=gray>~</> {$modelName} — already optimized");
                continue;
            }

            $this->line("  <fg=white;options=bold>{$modelName}</>");
            foreach ($changes as $change) {
                $this->line("    <fg=yellow>+</> {$change['desc']}");
            }

            if ($this->option('dry-run')) continue;

            $this->applyChanges($modelPath, $columns, $changes);
            $this->line("    <fg=green>✔</> Updated");
            $updated++;
        }

        $this->line('');
        $this->line("  <fg=green;options=bold>Done.</> {$updated} model(s) optimized");
        $this->line('');

        return self::SUCCESS;
    }

    private function analyzeModel(string $path, array $columns): array
    {
        $content = file_get_contents($path);
        $changes = [];
        $names   = collect($columns)->pluck('name')->toArray();

        // Missing $fillable
        if (!str_contains($content, '$fillable') && !str_contains($content, '$guarded')) {
            $fillable = $this->buildFillable($columns);
            $changes[] = ['type' => 'fillable', 'code' => $fillable, 'desc' => 'Add $fillable array'];
        }

        // Missing $casts for typed columns
        if (!str_contains($content, '$casts') && $this->hasCastableColumns($columns)) {
            $casts = $this->buildCasts($columns);
            $changes[] = ['type' => 'casts', 'code' => $casts, 'desc' => 'Add $casts (datetime, boolean, json columns)'];
        }

        // Missing $hidden for sensitive fields
        if (!str_contains($content, '$hidden') && $this->hasSensitiveColumns($names)) {
            $hidden = $this->buildHidden($names);
            $changes[] = ['type' => 'hidden', 'code' => $hidden, 'desc' => 'Add $hidden (password, tokens)'];
        }

        // Named scopes for status/type/active columns
        $scopeCode = $this->buildScopes($columns, $content);
        if ($scopeCode) {
            $changes[] = ['type' => 'scopes', 'code' => $scopeCode, 'desc' => 'Add named scopes (scopeActive, scopePublished, etc.)'];
        }

        return $changes;
    }

    private function applyChanges(string $path, array $columns, array $changes): void
    {
        $content = file_get_contents($path);

        // Inject properties after the class opening line (before first method)
        $propertyBlock = '';
        $scopeBlock    = '';

        foreach ($changes as $change) {
            if ($change['type'] === 'scopes') {
                $scopeBlock .= $change['code'];
            } else {
                $propertyBlock .= "\n" . $change['code'];
            }
        }

        if ($propertyBlock) {
            // Inject after class declaration line
            $content = preg_replace(
                '/(class \w+[^{]*\{)/',
                "$1\n{$propertyBlock}",
                $content,
                1
            );
        }

        if ($scopeBlock) {
            // Inject before closing brace
            $content = preg_replace('/\n}\s*$/', "\n{$scopeBlock}\n}", $content);
        }

        file_put_contents($path, $content);
    }

    private function buildFillable(array $columns): string
    {
        $skip = ['id', '_id', 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'email_verified_at', 'two_factor_secret', 'two_factor_recovery_codes'];
        $cols = collect($columns)
            ->pluck('name')
            ->filter(fn($n) => !in_array($n, $skip))
            ->map(fn($n) => "        '{$n}'")
            ->implode(",\n");

        return "    protected \$fillable = [\n{$cols},\n    ];";
    }

    private function buildCasts(array $columns): string
    {
        $casts = [];
        foreach ($columns as $col) {
            $name = $col['name'];
            $type = strtolower($col['type_name'] ?? '');

            $cast = match (true) {
                in_array($type, ['datetime', 'timestamp']) && !in_array($name, ['created_at', 'updated_at', 'deleted_at']) => 'datetime',
                $type === 'date'                           => 'date',
                in_array($type, ['boolean', 'bool', 'tinyint']) && str_starts_with($name, 'is_') => 'boolean',
                in_array($type, ['json', 'jsonb'])         => 'array',
                $type === 'decimal' || $type === 'float'   => "'decimal:2'",
                default => null,
            };

            if ($cast) {
                $castStr = $cast === "'decimal:2'" ? $cast : "'{$cast}'";
                $casts[] = "        '{$name}' => {$castStr},";
            }
        }

        if (empty($casts)) return '';

        $castsStr = implode("\n", $casts);
        return "    protected \$casts = [\n{$castsStr}\n    ];";
    }

    private function buildHidden(array $names): string
    {
        $sensitive = array_filter($names, fn($n) => in_array($n, [
            'password', 'remember_token', 'two_factor_secret',
            'two_factor_recovery_codes', 'api_key', 'secret_key',
        ]));

        if (empty($sensitive)) return '';

        $cols = collect($sensitive)->map(fn($n) => "        '{$n}'")->implode(",\n");
        return "    protected \$hidden = [\n{$cols},\n    ];";
    }

    private function buildScopes(array $columns, string $content): string
    {
        $scopes = '';
        $names  = collect($columns)->pluck('name')->toArray();

        if (in_array('status', $names) && !str_contains($content, 'scopeActive')) {
            $scopes .= <<<PHP

    public function scopeActive(\$query)
    {
        return \$query->where('status', 'active');
    }

    public function scopeInactive(\$query)
    {
        return \$query->where('status', 'inactive');
    }

PHP;
        }

        if (in_array('published_at', $names) && !str_contains($content, 'scopePublished')) {
            $scopes .= <<<PHP

    public function scopePublished(\$query)
    {
        return \$query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function scopeDraft(\$query)
    {
        return \$query->whereNull('published_at');
    }

PHP;
        }

        if (in_array('is_featured', $names) && !str_contains($content, 'scopeFeatured')) {
            $scopes .= <<<PHP

    public function scopeFeatured(\$query)
    {
        return \$query->where('is_featured', true);
    }

PHP;
        }

        return $scopes;
    }

    private function hasCastableColumns(array $columns): bool
    {
        $castable = ['datetime', 'timestamp', 'date', 'boolean', 'bool', 'tinyint', 'json', 'jsonb', 'decimal', 'float'];
        return collect($columns)->contains(fn($c) => in_array(strtolower($c['type_name'] ?? ''), $castable));
    }

    private function hasSensitiveColumns(array $names): bool
    {
        $sensitive = ['password', 'remember_token', 'two_factor_secret', 'api_key', 'secret_key'];
        return !empty(array_intersect($names, $sensitive));
    }
}

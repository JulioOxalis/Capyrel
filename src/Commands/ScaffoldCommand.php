<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Analyzers\Diagnostic;
use Julio\Capyrel\Analyzers\DiagnosticsRunner;
use Julio\Capyrel\Detectors\FrameworkDetector;
use Julio\Capyrel\Schema\SchemaAnalyzer;
use Julio\Capyrel\Schema\RelationshipDetector;
use Julio\Capyrel\Writers\ControllerWriter;
use Julio\Capyrel\Writers\ModelWriter;
use Julio\Capyrel\Writers\RouteWriter;
use Julio\Capyrel\Wizard\LaravelAwareness;

class ScaffoldCommand extends Command
{
    protected $signature = 'model:scaffold
                            {model?            : Only scaffold this specific model}
                            {--connection=     : Database connection to read from (default: app default)}
                            {--models          : Write to model files only}
                            {--controllers     : Generate/update controllers only}
                            {--routes          : Write resource routes to web.php}
                            {--dry-run         : Preview everything, write nothing}
                            {--force           : Skip all confirmation prompts}
                            {--analyze         : Run Python intelligence analysis and show recommendations}';

    protected $description = 'Detect DB relationships and scaffold models, controllers, and routes';

    private string $pythonScript;

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private RelationshipDetector $detector,
        private DiagnosticsRunner    $diagnostics,
        private ModelWriter          $modelWriter,
        private ControllerWriter     $controllerWriter,
        private RouteWriter          $routeWriter,
        private FrameworkDetector    $frameworkDetector,
        private LaravelAwareness     $awareness,
    ) {
        parent::__construct();
        $this->pythonScript = __DIR__ . '/../../python/capyrel_nlp.py';
    }

    public function handle(): int
    {
        $this->banner();

        $this->line('  <fg=gray>Connecting to database and reading schema...</>');

        $connection = $this->option('connection') ?? '';

        try {
            $this->analyzer->analyze($connection);
        } catch (\Throwable $e) {
            $this->error("  Cannot read schema: {$e->getMessage()}");
            $this->line('  Make sure your DB_* env variables are correct and the DB is running.');
            return self::FAILURE;
        }

        $all = $this->detector->detect();

        $target = $this->argument('model');
        if ($target) {
            $all = array_filter(
                $all,
                fn($key) => strtolower($key) === strtolower($target),
                ARRAY_FILTER_USE_KEY
            );

            if (empty($all)) {
                $this->warn("  No relationships detected for [{$target}].");
                $this->line('  Check that the table exists and has foreign key columns.');
                return self::FAILURE;
            }
        }

        if (empty($all)) {
            $this->warn('  No relationships detected in your schema.');
            return self::SUCCESS;
        }

        $this->displayRelationships($all);

        $issues = $this->diagnostics->run($all, $this->analyzer);
        $this->displayHealthCheck($issues);

        // ── Python intelligence analysis ───────────────────────────────────────
        if ($this->option('analyze') || $this->option('dry-run')) {
            $this->runPythonIntelligence($all);
        }

        if ($this->option('dry-run')) {
            $this->line('  <fg=yellow>Dry run — no files were changed.</>');
            return self::SUCCESS;
        }

        $fw = $this->frameworkDetector->detect();
        $this->line("  <fg=gray>CSS framework detected: <fg=white>{$fw}</></>");

        $onlyModels      = $this->option('models');
        $onlyControllers = $this->option('controllers');
        $onlyRoutes      = $this->option('routes');
        $specificFlag    = $onlyModels || $onlyControllers || $onlyRoutes;

        $writeModels      = $specificFlag ? $onlyModels      : true;
        $writeControllers = $specificFlag ? $onlyControllers : true;
        $writeRoutes      = $specificFlag ? $onlyRoutes      : true;

        if (!$this->option('force')) {
            $this->line('');

            if ($writeModels && !$this->confirm('  Write relationship methods into model files?', true)) {
                $writeModels = false;
            }
            if ($writeControllers && !$this->confirm('  Generate / update controller files?', true)) {
                $writeControllers = false;
            }
            if ($writeRoutes && !$this->confirm('  Write resource routes to routes/web.php?', true)) {
                $writeRoutes = false;
            }
        }

        if (!$writeModels && !$writeControllers && !$writeRoutes) {
            $this->line("\n  Nothing to write. Exiting.");
            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  <fg=gray>Writing files...</>');
        $this->line('');

        $totalModels      = 0;
        $totalControllers = 0;
        $modelNames       = array_keys($all);

        foreach ($all as $modelName => $rels) {
            $this->line("  <fg=cyan>■</> <fg=white;options=bold>{$modelName}</>");

            if ($writeModels) {
                $n = $this->scaffoldModel($modelName, $rels);
                $totalModels += $n;
            }

            if ($writeControllers) {
                $created = $this->scaffoldController($modelName, $rels);
                $totalControllers += (int) $created;
            }

            $this->line('');
        }

        if ($writeRoutes) {
            $this->line('  <fg=white;options=bold>Routes</>');
            if ($this->option('force')) {
                $mw = $this->routeWriter->writeSilent($modelNames);
                $this->line("  <fg=green>✔</> Routes written to web.php <fg=gray>(middleware: {$mw})</>");
            } else {
                $mw = $this->routeWriter->write(
                    $modelNames,
                    fn($q) => $this->confirm("  {$q}", true),
                    fn($cmd) => $this->runExternalCommand($cmd)
                );
                $this->line("  <fg=green>✔</> Routes written to web.php <fg=gray>(middleware: {$mw})</>");
            }
            $this->line('');
        }

        $this->line('  <fg=green;options=bold>✔ Capyrel scaffold complete.</>');
        $this->line("  <fg=gray>  {$totalModels} model method(s) added · {$totalControllers} controller(s) touched</>");
        $this->line('');
        $this->line('  <fg=gray>Tip: customise your controllers, then build your own UI — or run</>');
        $this->line('  <fg=gray>     <fg=cyan>php artisan capyrel:new</> to generate models + migrations interactively.</> ');
        $this->line('');

        return self::SUCCESS;
    }

    // ── Python intelligence ───────────────────────────────────────────────────

    private function runPythonIntelligence(array $all): void
    {
        $python = $this->resolvePythonBin();
        if ($python === null) return;

        $modelNames    = array_keys($all);
        $knownEntities = [];
        $anyResults    = false;

        $this->line('');
        $this->line('  <fg=white;options=bold>⚙ Python Intelligence Analysis</>');
        $this->line('');

        // Gather installed packages for context
        $packages = $this->getInstalledPackages();

        foreach ($all as $modelName => $rels) {
            $columns = $this->getColumnsForModel($modelName);

            // Convert DB column data → field specs the engine understands
            $fieldSpecs = $this->columnsToFieldSpecs($columns);

            $result = $this->runPythonPlan(
                $modelName,
                $fieldSpecs,
                $knownEntities,
                $packages,
                $python,
            );

            if (empty($result)) continue;

            $anyResults = true;
            $knownEntities[] = $modelName;

            $archetype  = $result['archetype'] ?? null;
            $confidence = $result['confidence'] ?? null;
            $warnings   = $result['warnings'] ?? [];

            $arch_label = $archetype
                ? " <fg=gray>archetype: <fg=white>{$archetype}</> ({$this->confidenceBar($confidence)})</>"
                : '';

            $this->line("  <fg=cyan>◆</> <fg=white;options=bold>{$modelName}</>{$arch_label}");

            // Show per-model warnings from the engine
            foreach ($warnings as $warning) {
                if (str_contains($warning, 'soft_deletes enabled') && isset($result['soft_deletes']) && $result['soft_deletes']) {
                    $this->line("    <fg=yellow>⚡</> Engine suggests: enable SoftDeletes (archetype: {$archetype})");
                } elseif (!str_contains($warning, 'timestamps')) {
                    $this->line("    <fg=yellow>⚠</> {$warning}");
                }
            }

            // Optimization: N+1 risk from hasMany relations
            $hasManyRels = array_filter($rels, fn($r) => $r['type'] === 'hasMany');
            if (count($hasManyRels) >= 2) {
                $targets = implode(', ', array_column($hasManyRels, 'related'));
                $this->line("    <fg=yellow>⚡</> N+1 risk: {$modelName} has " . count($hasManyRels) . " hasMany ({$targets}) — controller uses eager loading automatically");
            }

            // Security: sensitive fields in fillable
            $sensitiveFillable = $this->detectSensitiveFillable($result['fillable'] ?? [], $result['fields'] ?? []);
            foreach ($sensitiveFillable as $warn) {
                $this->line("    <fg=red>⚠ Security:</> {$warn}");
            }

            // Field hints from engine (Enum cast suggestions etc.)
            foreach ($result['fields'] ?? [] as $field) {
                if (!empty($field['hint'])) {
                    $this->line("    <fg=gray>💡 {$field['name']}:</> {$field['hint']}");
                }
            }

            $this->line('');
        }

        // Architecture-level suggestions
        if ($anyResults && count($modelNames) >= 4) {
            $this->line('  <fg=white;options=bold>Architecture Suggestions</>');
            $this->line('');

            $financialModels = array_filter($modelNames, fn($n) => in_array(strtolower($n), ['order','invoice','payment','subscription']));
            if (count($financialModels) >= 2) {
                $this->line('  <fg=yellow>⚡</> Financial models detected (' . implode(', ', $financialModels) . ') — consider Events + Listeners for audit trail');
            }

            $userOwnedCount = 0;
            foreach ($all as $modelName => $rels) {
                $cols = array_column($this->getColumnsForModel($modelName), 'name');
                if (in_array('user_id', $cols) || in_array('owner_id', $cols)) $userOwnedCount++;
            }
            if ($userOwnedCount >= 3) {
                $this->line("  <fg=yellow>⚡</> {$userOwnedCount} models have user_id — use Laravel Policies for ownership authorization");
            }

            if (count($modelNames) >= 10) {
                $this->line('  <fg=yellow>⚡</> Large schema (' . count($modelNames) . ' models) — consider Repository pattern for complex queries');
            }

            if ($this->awareness->hasScout()) {
                $this->line('  <fg=cyan>ℹ</> Laravel Scout detected — Searchable trait available for indexable models');
            }

            $this->line('');
        }
    }

    private function detectSensitiveFillable(array $fillable, array $fields): array
    {
        $sensitive = ['password', 'token', 'secret', 'api_key', 'two_factor', 'recovery'];
        $adminFields = ['is_admin', 'is_superuser', 'role', 'permissions', 'access_level'];
        $warnings = [];

        foreach ($fillable as $name) {
            foreach ($sensitive as $kw) {
                if (str_contains(strtolower($name), $kw)) {
                    $warnings[] = "{$name} is sensitive but in \$fillable — remove it";
                    break;
                }
            }
            if (in_array($name, $adminFields)) {
                $warnings[] = "{$name} looks like a privilege field in \$fillable — mass-assignment risk";
            }
        }

        return $warnings;
    }

    private function columnsToFieldSpecs(array $columns): array
    {
        $specs = [];
        $typeMap = [
            'varchar'    => 'string',   'char'       => 'string',
            'text'       => 'text',     'mediumtext' => 'text',   'longtext' => 'text',
            'int'        => 'integer',  'integer'    => 'integer', 'bigint'   => 'integer',
            'tinyint'    => 'boolean',  'boolean'    => 'boolean',
            'decimal'    => 'decimal',  'float'      => 'decimal', 'double' => 'decimal',
            'datetime'   => 'timestamp','timestamp'  => 'timestamp',
            'date'       => 'date',
            'json'       => 'json',     'jsonb'      => 'json',
            'enum'       => 'string',
        ];

        foreach ($columns as $col) {
            $name     = $col['name'];
            $dbType   = strtolower($col['type_name'] ?? 'varchar');
            $nullable = (bool) ($col['nullable'] ?? false);

            if (str_ends_with($name, '_id')) {
                $spec = $name;
            } else {
                $type = $typeMap[$dbType] ?? 'string';
                $spec = $name . ':' . $type;
                if ($nullable) $spec .= ':null';
            }

            $specs[] = $spec;
        }

        return $specs;
    }

    private function runPythonPlan(
        string $modelName,
        array  $fieldSpecs,
        array  $knownEntities,
        array  $packages,
        string $python,
    ): array {
        if (!file_exists($this->pythonScript)) return [];

        $script  = escapeshellarg($this->pythonScript);
        $entity  = escapeshellarg($modelName);
        $specs   = escapeshellarg(json_encode($fieldSpecs));
        $known   = escapeshellarg(json_encode($knownEntities));
        $pkgs    = escapeshellarg(implode(',', $packages));

        $cmd = PHP_OS_FAMILY === 'Windows'
            ? "{$python} {$script} plan {$entity} {$specs} {$known} --packages={$pkgs} 2>NUL"
            : "{$python} {$script} plan {$entity} {$specs} {$known} --packages={$pkgs} 2>/dev/null";

        $output  = shell_exec($cmd);
        if (empty($output)) return [];

        $decoded = json_decode(trim($output), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function getInstalledPackages(): array
    {
        $composerPath = base_path('composer.json');
        if (!file_exists($composerPath)) return [];

        $composer = json_decode(file_get_contents($composerPath), true) ?? [];
        return array_keys(array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []));
    }

    private function resolvePythonBin(): ?string
    {
        foreach (['python3', 'python'] as $bin) {
            $test = shell_exec("{$bin} --version 2>&1");
            if ($test && str_contains($test, 'Python 3')) {
                return $bin;
            }
        }
        return null;
    }

    private function confidenceBar(float|null $conf): string
    {
        if ($conf === null) return '';
        $pct = (int) round($conf * 100);
        return "{$pct}%";
    }

    // ── Scaffold helpers ──────────────────────────────────────────────────────

    private function scaffoldModel(string $modelName, array $rels): int
    {
        $path = $this->modelWriter->findModelPath($modelName);

        if (!$path) {
            $this->line("    <fg=yellow>⚠</> Model not found: app/Models/{$modelName}.php — skipped");
            return 0;
        }

        $columns = $this->getColumnsForModel($modelName);
        $indexes = $this->getIndexesForModel($modelName);
        $added   = $this->modelWriter->write($path, $rels, $columns, $indexes);

        if ($added > 0) {
            $this->line("    <fg=green>✔</> Model enhanced <fg=gray>({$added} feature(s) added: relationships, casts, scopes, accessors)</>");
        } else {
            $this->line("    <fg=gray>~ Model unchanged (already up to date)</>");
        }

        return $added;
    }

    private function scaffoldController(string $modelName, array $rels): bool
    {
        $path = $this->controllerWriter->findControllerPath($modelName);

        if (!$path) {
            if ($this->option('force') || $this->confirm("    Create {$modelName}Controller.php?", true)) {
                $columns = $this->getColumnsForModel($modelName);
                $code = $this->controllerWriter->generate($modelName, $rels, $columns);
                $dest = app_path("Http/Controllers/{$modelName}Controller.php");
                file_put_contents($dest, $code);
                $this->line("    <fg=green>✔</> Controller created <fg=gray>({$modelName}Controller.php)</>");
                return true;
            }
            return false;
        }

        $injected = $this->controllerWriter->inject($path, $modelName, $rels);

        if ($injected) {
            $this->line("    <fg=green>✔</> Controller updated <fg=gray>(eager loading injected)</>");
        } else {
            $this->line("    <fg=gray>~ Controller unchanged</>");
        }

        return $injected;
    }

    private function getColumnsForModel(string $modelName): array
    {
        foreach ($this->analyzer->getTables() as $table) {
            if (Str::studly(Str::singular($table)) === $modelName) {
                return $this->analyzer->getColumns($table);
            }
        }
        return [];
    }

    private function getIndexesForModel(string $modelName): array
    {
        foreach ($this->analyzer->getTables() as $table) {
            if (Str::studly(Str::singular($table)) === $modelName) {
                return $this->analyzer->getIndexes($table);
            }
        }
        return [];
    }

    private function runExternalCommand(string $cmd): void
    {
        $this->line("  <fg=gray>Running: {$cmd}</>");
        passthru($cmd);
    }

    // ── Health check display ──────────────────────────────────────────────────

    private function displayHealthCheck(array $issues): void
    {
        if (empty($issues)) {
            $this->line('  <fg=green>⚕ Health check — no issues found.</>');
            $this->line('');
            return;
        }

        $errors   = array_filter($issues, fn($d) => $d->level === Diagnostic::ERROR);
        $warnings = array_filter($issues, fn($d) => $d->level === Diagnostic::WARNING);
        $infos    = array_filter($issues, fn($d) => $d->level === Diagnostic::INFO);

        $summary = implode(' · ', array_filter([
            count($errors)   ? '<fg=red>' . count($errors) . ' error(s)</>'     : '',
            count($warnings) ? '<fg=yellow>' . count($warnings) . ' warning(s)</>' : '',
            count($infos)    ? '<fg=gray>' . count($infos) . ' info</>'          : '',
        ]));

        $this->line("  <fg=white;options=bold>⚕ Health Check</> — {$summary}");
        $this->line('');

        foreach ($issues as $issue) {
            [$icon, $color] = match ($issue->level) {
                Diagnostic::ERROR   => ['✖', 'red'],
                Diagnostic::WARNING => ['⚠', 'yellow'],
                default             => ['ℹ', 'gray'],
            };

            $model = $issue->model ? "<fg=cyan>[{$issue->model}]</> " : '';
            $this->line("  <fg={$color}>{$icon}</> {$model}<fg=white>{$issue->message}</>");
            $this->line("    <fg=gray>↳ {$issue->suggestion}</>");
            $this->line('');
        }
    }

    // ── Display ───────────────────────────────────────────────────────────────

    private function displayRelationships(array $all): void
    {
        $this->line('');
        $this->line('  <fg=yellow;options=bold>Detected relationships:</>');
        $this->line('');

        $typeColors = [
            'hasOne'         => 'green',
            'hasMany'        => 'green',
            'belongsTo'      => 'blue',
            'belongsToMany'  => 'magenta',
            'hasManyThrough' => 'cyan',
            'hasOneThrough'  => 'cyan',
            'morphTo'        => 'yellow',
            'morphMany'      => 'yellow',
        ];

        foreach ($all as $modelName => $rels) {
            $this->line("  <fg=white;options=bold>{$modelName}</>");
            $last = array_key_last($rels);

            foreach ($rels as $i => $rel) {
                $tree    = $i === $last ? '  └──' : '  ├──';
                $color   = $typeColors[$rel['type']] ?? 'white';
                $type    = str_pad($rel['type'], 16);
                $related = $rel['related'] ?: '(polymorphic)';
                $via     = $rel['via'];

                $this->line("  {$tree} <fg={$color}>{$type}</> → <fg=white>{$related}</> <fg=gray>[{$via}]</>");
            }

            $this->line('');
        }
    }

    // ── Banner ────────────────────────────────────────────────────────────────

    private function banner(): void
    {
        $this->line('');
        $this->line('  <fg=cyan>  ____                          _ </>');
        $this->line('  <fg=cyan> / ___|__ _ _ __  _   _ _ __ ___| |</>');
        $this->line('  <fg=cyan>| |   / _` | \'_ \| | | | \'__/ _ \ |</>');
        $this->line('  <fg=cyan>| |__| (_| | |_) | |_| | | |  __/ |</>');
        $this->line('  <fg=cyan> \____\__,_| .__/ \__, |_|  \___|_|</>');
        $this->line('  <fg=cyan>           |_|    |___/             </>');
        $this->line('  <fg=gray>  by julio · laravel scaffolding</>');
        $this->line('');
    }
}

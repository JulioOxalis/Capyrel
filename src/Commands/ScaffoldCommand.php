<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Analyzers\Diagnostic;
use Julio\Capyrel\Analyzers\DiagnosticsRunner;
use Julio\Capyrel\Detectors\FrameworkDetector;
use Julio\Capyrel\Generators\FullBladeGenerator;
use Julio\Capyrel\Schema\SchemaAnalyzer;
use Julio\Capyrel\Schema\RelationshipDetector;
use Julio\Capyrel\Writers\ModelWriter;
use Julio\Capyrel\Writers\ControllerWriter;
use Julio\Capyrel\Writers\BladeWriter;
use Julio\Capyrel\Writers\RouteWriter;

class ScaffoldCommand extends Command
{
    protected $signature = 'model:scaffold
                            {model?            : Only scaffold this specific model}
                            {--connection=     : Database connection to read from (default: app default)}
                            {--models          : Write to model files only}
                            {--controllers     : Generate/update controllers only}
                            {--views           : Generate modal-first index blade pages}
                            {--routes          : Write resource routes to web.php}
                            {--dry-run         : Preview everything, write nothing}
                            {--force           : Skip all confirmation prompts}';

    protected $description = 'Detect DB relationships and scaffold models, controllers, and modal-first blade views';

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private RelationshipDetector $detector,
        private DiagnosticsRunner    $diagnostics,
        private ModelWriter          $modelWriter,
        private ControllerWriter     $controllerWriter,
        private BladeWriter          $bladeWriter,
        private FullBladeGenerator   $bladeGenerator,
        private RouteWriter          $routeWriter,
        private FrameworkDetector    $frameworkDetector,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->banner();

        // ── Analyze ──────────────────────────────────────────────────────────
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

        // ── Filter to requested model ─────────────────────────────────────────
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

        // ── Display ───────────────────────────────────────────────────────────
        $this->displayRelationships($all);

        // ── Health check ─────────────────────────────────────────────────────
        $issues = $this->diagnostics->run($all, $this->analyzer);
        $this->displayHealthCheck($issues);

        if ($this->option('dry-run')) {
            $this->line('  <fg=yellow>Dry run — no files were changed.</>');
            return self::SUCCESS;
        }

        // ── Confirm what to write ─────────────────────────────────────────────
        $fw = $this->frameworkDetector->detect();
        $this->line("  <fg=gray>CSS framework detected: <fg=white>{$fw}</></>");

        $onlyModels      = $this->option('models');
        $onlyControllers = $this->option('controllers');
        $onlyViews       = $this->option('views');
        $onlyRoutes      = $this->option('routes');
        $specificFlag    = $onlyModels || $onlyControllers || $onlyViews || $onlyRoutes;

        $writeModels      = $specificFlag ? $onlyModels      : true;
        $writeControllers = $specificFlag ? $onlyControllers : true;
        $writeViews       = $specificFlag ? $onlyViews       : true;
        $writeRoutes      = $specificFlag ? $onlyRoutes      : true;

        if (!$this->option('force')) {
            $this->line('');

            if ($writeModels && !$this->confirm('  Write relationship methods into model files?', true)) {
                $writeModels = false;
            }
            if ($writeControllers && !$this->confirm('  Generate / update controller files?', true)) {
                $writeControllers = false;
            }
            if ($writeViews && !$this->confirm('  Generate modal-first index pages (create, edit, view, delete modals inline)?', true)) {
                $writeViews = false;
            }
            if ($writeRoutes && !$this->confirm('  Write resource routes to routes/web.php?', true)) {
                $writeRoutes = false;
            }
        }

        if (!$writeModels && !$writeControllers && !$writeViews && !$writeRoutes) {
            $this->line("\n  Nothing to write. Exiting.");
            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  <fg=gray>Writing files...</>');
        $this->line('');

        // ── Process each model ────────────────────────────────────────────────
        $totalModels      = 0;
        $totalControllers = 0;
        $totalViews       = 0;
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

            if ($writeViews) {
                $created = $this->scaffoldFullBlades($modelName, $rels);
                $totalViews += (int) $created;
            }

            $this->line('');
        }

        // ── Routes ────────────────────────────────────────────────────────────
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

        // ── Summary ───────────────────────────────────────────────────────────
        $this->line('  <fg=green;options=bold>✔ Capyrel scaffold complete.</>');
        $this->line("  <fg=gray>  {$totalModels} model method(s) added · {$totalControllers} controller(s) touched · {$totalViews} index page(s) generated</>");
        $this->line('');
        $this->line('  <fg=gray>Tip: adjust validation rules in controllers and customise blade pages as needed.</>');
        $this->line('');

        return self::SUCCESS;
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

    private function scaffoldFullBlades(string $modelName, array $rels): bool
    {
        $folder  = Str::kebab(Str::plural($modelName));
        $viewDir = resource_path("views/{$folder}");

        if (!is_dir($viewDir)) {
            mkdir($viewDir, 0755, true);
        }

        $columns = $this->getColumnsForModel($modelName);
        $fw      = $this->frameworkDetector->detect();
        $path    = "{$viewDir}/index.blade.php";

        if (file_exists($path)) {
            $this->line("    <fg=gray>~ {$folder}/index.blade.php already exists</>");
            return false;
        }

        file_put_contents($path, $this->bladeGenerator->generateIndex($modelName, $columns, $rels));
        $this->line("    <fg=green>✔</> Created <fg=white>{$folder}/index.blade.php</> <fg=gray>({$fw} · modals inline)</>");
        return true;
    }

    private function getColumnsForModel(string $modelName): array
    {
        foreach ($this->analyzer->getTables() as $table) {
            if (\Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($table)) === $modelName) {
                return $this->analyzer->getColumns($table);
            }
        }
        return [];
    }

    private function getIndexesForModel(string $modelName): array
    {
        foreach ($this->analyzer->getTables() as $table) {
            if (\Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($table)) === $modelName) {
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

            $model  = $issue->model ? "<fg=cyan>[{$issue->model}]</> " : '';
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

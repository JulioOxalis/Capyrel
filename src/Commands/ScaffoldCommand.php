<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Analyzers\Diagnostic;
use Julio\Capyrel\Analyzers\DiagnosticsRunner;
use Julio\Capyrel\Schema\SchemaAnalyzer;
use Julio\Capyrel\Schema\RelationshipDetector;
use Julio\Capyrel\Writers\ModelWriter;
use Julio\Capyrel\Writers\ControllerWriter;
use Julio\Capyrel\Writers\BladeWriter;

class ScaffoldCommand extends Command
{
    protected $signature = 'model:scaffold
                            {model?            : Only scaffold this specific model}
                            {--connection=     : Database connection to read from (default: app default)}
                            {--models          : Write to model files only}
                            {--controllers     : Generate/update controllers only}
                            {--views           : Add blade comments only}
                            {--dry-run         : Preview everything, write nothing}
                            {--force           : Skip all confirmation prompts}';

    protected $description = 'Detect DB relationships and scaffold models, controllers, and blade views';

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private RelationshipDetector $detector,
        private DiagnosticsRunner    $diagnostics,
        private ModelWriter          $modelWriter,
        private ControllerWriter     $controllerWriter,
        private BladeWriter          $bladeWriter,
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
        $onlyModels      = $this->option('models');
        $onlyControllers = $this->option('controllers');
        $onlyViews       = $this->option('views');
        $specificFlag    = $onlyModels || $onlyControllers || $onlyViews;

        $writeModels      = $specificFlag ? $onlyModels      : true;
        $writeControllers = $specificFlag ? $onlyControllers : true;
        $writeViews       = $specificFlag ? $onlyViews       : true;

        if (!$this->option('force')) {
            $this->line('');

            if ($writeModels && !$this->confirm('  Write relationship methods into model files?', true)) {
                $writeModels = false;
            }
            if ($writeControllers && !$this->confirm('  Generate / update controller files?', true)) {
                $writeControllers = false;
            }
            if ($writeViews && !$this->confirm('  Add blade usage comments to view files?', true)) {
                $writeViews = false;
            }
        }

        if (!$writeModels && !$writeControllers && !$writeViews) {
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
                $created = $this->scaffoldView($modelName, $rels);
                $totalViews += (int) $created;
            }

            $this->line('');
        }

        // ── Summary ───────────────────────────────────────────────────────────
        $this->line('  <fg=green;options=bold>✔ Capyrel scaffold complete.</>');
        $this->line("  <fg=gray>  {$totalModels} model method(s) added · {$totalControllers} controller(s) touched · {$totalViews} view(s) touched</>");
        $this->line('');
        $this->line('  <fg=gray>Tip: uncomment the blade placeholders and adjust validation rules in controllers.</>');
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

        $added = $this->modelWriter->write($path, $rels);

        if ($added > 0) {
            $this->line("    <fg=green>✔</> Model updated <fg=gray>({$added} method(s) added)</>");
        } else {
            $this->line("    <fg=gray>~ Model unchanged (methods already present)</>");
        }

        return $added;
    }

    private function scaffoldController(string $modelName, array $rels): bool
    {
        $path = $this->controllerWriter->findControllerPath($modelName);

        if (!$path) {
            if ($this->option('force') || $this->confirm("    Create {$modelName}Controller.php?", true)) {
                $code = $this->controllerWriter->generate($modelName, $rels);
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

    private function scaffoldView(string $modelName, array $rels): bool
    {
        $viewPath = $this->bladeWriter->resolveViewPath($modelName);
        $comments = $this->bladeWriter->generateComments($modelName, $rels);
        $written  = $this->bladeWriter->writeToView($viewPath, $comments);
        $label    = Str::kebab(Str::plural($modelName)) . '/show.blade.php';

        if ($written) {
            $this->line("    <fg=green>✔</> Blade updated <fg=gray>({$label})</>");
        } else {
            $this->line("    <fg=gray>~ Blade unchanged ({$label})</>");
        }

        return $written;
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

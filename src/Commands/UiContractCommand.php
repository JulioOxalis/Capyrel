<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Schema\SchemaAnalyzer;
use Julio\Capyrel\Schema\RelationshipDetector;
use Julio\Capyrel\UI\UiContractBuilder;
use Julio\Capyrel\UI\UiAdapterRegistry;
use Julio\Capyrel\UI\Contracts\HasExtraFiles;

/**
 * php artisan capyrel:ui:scaffold {model?}
 *
 * Pipeline:
 *   Schema → UiContractBuilder → UiAdapterRegistry → Blade output
 *
 * Options:
 *   --adapter=blade-basic   Override the resolved adapter
 *   --dump-contract         Print the UI Contract JSON and stop (no files written)
 *   --screens=list,create,show   Only generate the listed screens
 *   --dry-run               Preview file paths without writing
 *   --force                 Overwrite existing files
 */
class UiContractCommand extends Command
{
    protected $signature = 'capyrel:ui:scaffold
                            {model?              : Generate for this model only}
                            {--connection=       : Database connection}
                            {--adapter=          : Force a specific UI adapter}
                            {--screens=          : Comma-separated screens to generate (list,create,show)}
                            {--dump-contract     : Print the UI Contract JSON and exit}
                            {--dry-run           : Preview file paths, write nothing}
                            {--force             : Overwrite existing view files}';

    protected $description = 'Generate Blade views via the UI Contract pipeline (schema → contract → adapter → blade)';

    public function __construct(
        private SchemaAnalyzer      $analyzer,
        private RelationshipDetector $detector,
        private UiContractBuilder   $contractBuilder,
        private UiAdapterRegistry   $registry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->banner();

        $connection = $this->option('connection') ?? '';

        try {
            $this->analyzer->analyze($connection);
        } catch (\Throwable $e) {
            $this->error("  Cannot read schema: {$e->getMessage()}");
            return self::FAILURE;
        }

        $allRelations = $this->detector->detect();
        $target       = $this->argument('model');
        $screenFilter = $this->parseScreens($this->option('screens'));
        $adapterName  = $this->option('adapter') ?: config('capyrel.ui.adapter', 'blade-basic');

        if ($this->option('adapter')) {
            $this->registry->setDefault($adapterName);
        }

        $skip = ['migrations', 'cache', 'sessions', 'jobs', 'failed_jobs', 'job_batches', 'password_reset_tokens', 'cache_locks'];

        $written = 0;

        foreach ($this->analyzer->getTables() as $table) {
            if (in_array($table, $skip, true)) continue;
            if ($this->analyzer->isPivotTable($table)) continue;

            $modelName = Str::studly(Str::singular($table));
            if ($target && strtolower($modelName) !== strtolower($target)) continue;

            $columns      = $this->analyzer->getColumns($table);
            $relationships = $allRelations[$modelName] ?? [];

            if (empty($columns)) continue;

            $contract = $this->contractBuilder->build($modelName, $columns, $relationships);

            if ($this->option('dump-contract')) {
                $this->dumpContract($modelName, $contract);
                continue;
            }

            $adapter  = $this->registry->resolve($modelName);
            $written += $this->generateViews($modelName, $contract, $adapter, $screenFilter, $adapterName);
        }

        if (!$this->option('dump-contract')) {
            $this->line('');
            $this->line("  <fg=green;options=bold>Done.</> {$written} file(s) written via <fg=cyan>{$adapterName}</> adapter.");
            $this->line('');
        }

        return self::SUCCESS;
    }

    // ── Generation ────────────────────────────────────────────────────────────

    private function generateViews(string $model, array $contract, $adapter, array $screenFilter, string $adapterName): int
    {
        $folder   = Str::kebab(Str::plural($model));
        $viewDir  = resource_path("views/{$folder}");
        $dryRun   = $this->option('dry-run');
        $force    = $this->option('force');
        $written  = 0;

        if (!$dryRun && !is_dir($viewDir)) {
            mkdir($viewDir, 0755, true);
        }

        $this->line("  <fg=white;options=bold>{$model}</> <fg=gray>[{$adapterName}]</>");

        $screens = [
            'list'   => ['render' => fn() => $adapter->renderList($contract),   'file' => 'index.blade.php'],
            'create' => ['render' => fn() => $adapter->renderCreate($contract),  'file' => 'create.blade.php'],
            'edit'   => ['render' => fn() => $adapter->renderEdit($contract),    'file' => 'edit.blade.php'],
            'show'   => ['render' => fn() => $adapter->renderShow($contract),    'file' => 'show.blade.php'],
        ];

        foreach ($screens as $screen => $spec) {
            if (!empty($screenFilter) && !in_array($screen, $screenFilter, true)) continue;

            $path  = "{$viewDir}/{$spec['file']}";
            $label = "resources/views/{$folder}/{$spec['file']}";

            if ($dryRun) {
                $this->line("    <fg=cyan>[preview]</> {$label}");
                $written++;
            } elseif (file_exists($path) && !$force) {
                $this->line("    <fg=gray>~</> {$label} <fg=gray>(exists — use --force to overwrite)</>");
            } else {
                file_put_contents($path, ($spec['render'])());
                $this->line("    <fg=green>✔</> {$label}");
                $written++;
            }

            // Write extra files emitted by HasExtraFiles adapters (e.g. Livewire components)
            if ($adapter instanceof HasExtraFiles) {
                foreach ($adapter->extraFiles($screen, $contract) as $extraPath => $extraContent) {
                    $extraLabel = $this->relativeLabel($extraPath);

                    if ($dryRun) {
                        $this->line("    <fg=cyan>[preview]</> {$extraLabel}");
                        $written++;
                        continue;
                    }

                    $extraDir = dirname($extraPath);
                    if (!is_dir($extraDir)) {
                        mkdir($extraDir, 0755, true);
                    }

                    if (file_exists($extraPath) && !$force) {
                        $this->line("    <fg=gray>~</> {$extraLabel} <fg=gray>(exists — use --force to overwrite)</>");
                        continue;
                    }

                    file_put_contents($extraPath, $extraContent);
                    $this->line("    <fg=green>✔</> {$extraLabel}");
                    $written++;
                }
            }
        }

        $this->line('');
        return $written;
    }

    // ── Contract dump ─────────────────────────────────────────────────────────

    private function dumpContract(string $model, array $contract): void
    {
        $this->line('');
        $this->line("  <fg=yellow;options=bold>UI Contract — {$model}</>");
        $this->line('');
        $this->line(json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function relativeLabel(string $absolutePath): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($absolutePath, $base)
            ? substr($absolutePath, strlen($base))
            : $absolutePath;
    }

    private function parseScreens(?string $screens): array
    {
        if (!$screens) return [];
        return array_map('trim', explode(',', $screens));
    }

    private function banner(): void
    {
        $this->line('');
        $this->line('  <fg=cyan;options=bold>Capyrel UI — Contract Pipeline</>');
        $this->line('  <fg=gray>schema → contract → adapter → blade</>');
        $this->line('');
    }
}

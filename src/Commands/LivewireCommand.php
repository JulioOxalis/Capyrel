<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Generators\LivewireGenerator;
use Julio\Capyrel\Schema\SchemaAnalyzer;
use Julio\Capyrel\Schema\RelationshipDetector;

class LivewireCommand extends Command
{
    protected $signature = 'model:livewire
                            {model?         : Generate for a specific model only}
                            {--connection=  : Database connection to use}
                            {--force        : Overwrite existing files}
                            {--dry-run      : Preview without writing}';

    protected $description = 'Generate Livewire components (searchable table + live form) for every model';

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private RelationshipDetector $detector,
        private LivewireGenerator    $generator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Check if Livewire is installed
        $composer = json_decode(file_get_contents(base_path('composer.json')), true) ?? [];
        $packages = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);

        if (!isset($packages['livewire/livewire'])) {
            $this->line('');
            $this->warn('  Livewire is not installed.');
            if ($this->confirm('  Install Livewire now?', true)) {
                $this->line('  Installing Livewire...');
                passthru('composer require livewire/livewire');
            } else {
                $this->error('  Install Livewire first: composer require livewire/livewire');
                return self::FAILURE;
            }
        }

        $connection = $this->option('connection') ?? '';

        try {
            $this->analyzer->analyze($connection);
        } catch (\Throwable $e) {
            $this->error("Cannot read schema: {$e->getMessage()}");
            return self::FAILURE;
        }

        $target         = $this->argument('model');
        $livewirePath   = app_path('Livewire');
        $livewireViews  = resource_path('views/livewire');
        $skip           = ['migrations', 'cache', 'sessions', 'jobs', 'failed_jobs', 'job_batches', 'password_reset_tokens', 'cache_locks'];

        if (!$this->option('dry-run')) {
            foreach ([$livewirePath, $livewireViews] as $dir) {
                if (!is_dir($dir)) mkdir($dir, 0755, true);
            }
        }

        $this->line('');
        $this->line('  <fg=cyan;options=bold>Generating Livewire Components...</>');
        $this->line('');

        $created = 0;

        foreach ($this->analyzer->getTables() as $table) {
            if (in_array($table, $skip)) continue;
            if ($this->analyzer->isPivotTable($table)) continue;

            $modelName = Str::studly(Str::singular($table));
            if ($target && strtolower($modelName) !== strtolower($target)) continue;

            $columns    = $this->analyzer->getColumns($table);
            $kebab      = Str::kebab($modelName);

            if (empty($columns)) continue;

            $files = [
                app_path("Livewire/{$modelName}Table.php")             => $this->generator->generateTableComponent($modelName, $columns),
                resource_path("views/livewire/{$kebab}-table.blade.php") => $this->generator->generateTableBlade($modelName, $columns),
                app_path("Livewire/{$modelName}Form.php")              => $this->generator->generateFormComponent($modelName, $columns),
                resource_path("views/livewire/{$kebab}-form.blade.php")  => $this->generator->generateFormBlade($modelName, $columns),
            ];

            $this->line("  <fg=white;options=bold>{$modelName}</>");

            foreach ($files as $path => $code) {
                $label = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path);

                if ($this->option('dry-run')) {
                    $this->line("    <fg=cyan>[preview]</> {$label}");
                    $created++;
                    continue;
                }

                if (file_exists($path) && !$this->option('force')) {
                    $this->line("    <fg=gray>~</> {$label} exists");
                    continue;
                }

                file_put_contents($path, $code);
                $this->line("    <fg=green>✔</> {$label}");
                $created++;
            }

            $this->line('');
        }

        $this->line("  <fg=green;options=bold>Done.</> {$created} file(s) created");
        $this->line("  <fg=gray>Usage in blade: <livewire:{$this->lastKebab($target)}--table /> and <livewire:{$this->lastKebab($target)}--form /></>");
        $this->line('');

        return self::SUCCESS;
    }

    private function lastKebab(?string $model): string
    {
        return $model ? Str::kebab($model) : 'model-name';
    }
}

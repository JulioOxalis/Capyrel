<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Generators\EventGenerator;
use Julio\Capyrel\Schema\SchemaAnalyzer;

class EventsCommand extends Command
{
    protected $signature = 'model:events
                            {model?         : Generate for a specific model only}
                            {--connection=  : Database connection to use}
                            {--force        : Overwrite existing files}
                            {--dry-run      : Preview without writing}';

    protected $description = 'Generate Events (Created, Updated, Deleted) and Observer stubs for every model';

    public function __construct(
        private SchemaAnalyzer $analyzer,
        private EventGenerator $generator,
    ) {
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

        $target        = $this->argument('model');
        $eventsPath    = app_path('Events');
        $observersPath = app_path('Observers');
        $skip          = ['migrations', 'cache', 'sessions', 'jobs', 'failed_jobs', 'job_batches', 'password_reset_tokens', 'cache_locks'];

        if (!$this->option('dry-run')) {
            foreach ([$eventsPath, $observersPath] as $dir) {
                if (!is_dir($dir)) mkdir($dir, 0755, true);
            }
        }

        $this->line('');
        $this->line('  <fg=cyan;options=bold>Generating Events + Observers...</>');
        $this->line('');

        $created      = 0;
        $skipped      = 0;
        $modelNames   = [];

        foreach ($this->analyzer->getTables() as $table) {
            if (in_array($table, $skip)) continue;
            if ($this->analyzer->isPivotTable($table)) continue;

            $modelName    = Str::studly(Str::singular($table));
            if ($target && strtolower($modelName) !== strtolower($target)) continue;

            $hasSoftDel   = $this->analyzer->hasSoftDeletes($table);
            $modelNames[] = $modelName;

            $this->line("  <fg=white;options=bold>{$modelName}</>");

            // Generate events
            foreach (['Created', 'Updated', 'Deleted'] as $action) {
                $eventName = $modelName . $action;
                $filePath  = "{$eventsPath}/{$eventName}.php";
                $code      = $this->generator->generateEvent($modelName, $action);

                if ($this->option('dry-run')) {
                    $this->line("    <fg=cyan>[event]</> App\\Events\\{$eventName}");
                } elseif (!file_exists($filePath) || $this->option('force')) {
                    file_put_contents($filePath, $code);
                    $this->line("    <fg=green>✔</> App\\Events\\{$eventName}");
                    $created++;
                } else {
                    $skipped++;
                }
            }

            // Generate observer
            $observerPath = "{$observersPath}/{$modelName}Observer.php";
            $observerCode = $this->generator->generateObserver($modelName, $hasSoftDel);

            if ($this->option('dry-run')) {
                $this->line("    <fg=cyan>[observer]</> App\\Observers\\{$modelName}Observer");
            } elseif (!file_exists($observerPath) || $this->option('force')) {
                file_put_contents($observerPath, $observerCode);
                $this->line("    <fg=green>✔</> App\\Observers\\{$modelName}Observer");
                $created++;
            } else {
                $skipped++;
            }

            $this->line('');
        }

        if (!$this->option('dry-run') && !empty($modelNames)) {
            $reg     = $this->generator->generateEventServiceProviderRegistration($modelNames);
            $regPath = base_path('capyrel-observer-registrations.php');
            file_put_contents($regPath, $reg);
            $this->line("  <fg=green>✔</> Registration snippet saved to <fg=white>capyrel-observer-registrations.php</>");
            $this->line("  <fg=gray>  Paste the contents into AppServiceProvider::boot()</>");
        }

        $this->line('');
        $this->line("  <fg=green;options=bold>Done.</> {$created} file(s) created · {$skipped} skipped");
        $this->line('');

        return self::SUCCESS;
    }
}

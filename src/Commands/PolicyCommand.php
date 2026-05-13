<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Generators\PolicyGenerator;
use Julio\Capyrel\Schema\SchemaAnalyzer;
use Julio\Capyrel\Schema\RelationshipDetector;

class PolicyCommand extends Command
{
    protected $signature = 'model:policy
                            {model?         : Generate for a specific model only}
                            {--connection=  : Database connection to use}
                            {--force        : Overwrite existing policy files}
                            {--dry-run      : Preview without writing}
                            {--register     : Auto-register policies in AuthServiceProvider}';

    protected $description = 'Generate Laravel Policies from detected model relationships and ownership columns';

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private RelationshipDetector $detector,
        private PolicyGenerator      $generator,
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

        $relationships = $this->detector->detect();
        $target        = $this->argument('model');
        $policiesPath  = app_path('Policies');
        $skip          = ['migrations', 'cache', 'sessions', 'jobs', 'failed_jobs', 'job_batches', 'password_reset_tokens', 'cache_locks'];

        if (!is_dir($policiesPath) && !$this->option('dry-run')) {
            mkdir($policiesPath, 0755, true);
        }

        $this->line('');
        $this->line('  <fg=cyan;options=bold>Generating Policies...</>');
        $this->line('');

        $created      = 0;
        $skipped      = 0;
        $createdNames = [];

        foreach ($this->analyzer->getTables() as $table) {
            if (in_array($table, $skip)) continue;
            if ($this->analyzer->isPivotTable($table)) continue;

            $modelName = Str::studly(Str::singular($table));
            if ($target && strtolower($modelName) !== strtolower($target)) continue;

            $columns  = $this->analyzer->getColumns($table);
            $rels     = $relationships[$modelName] ?? [];
            $filePath = "{$policiesPath}/{$modelName}Policy.php";

            if (empty($columns)) continue;

            $code = $this->generator->generate($modelName, $columns, $rels);

            if ($this->option('dry-run')) {
                $ownerCol = $this->detectOwnerColumn($columns);
                $this->line("  <fg=cyan>[preview]</> {$modelName}Policy — owner check: <fg=white>{$ownerCol}</>");
                $created++;
                continue;
            }

            if (file_exists($filePath) && !$this->option('force')) {
                $this->line("  <fg=gray>~</> {$modelName}Policy.php already exists");
                $skipped++;
                continue;
            }

            file_put_contents($filePath, $code);
            $this->line("  <fg=green>✔</> Created <fg=white>app/Policies/{$modelName}Policy.php</>");
            $created++;
            $createdNames[] = $modelName;
        }

        $this->line('');
        $this->line("  <fg=green;options=bold>Done.</> {$created} policy(ies) created · {$skipped} skipped");

        if (!empty($createdNames) && !$this->option('dry-run')) {
            $this->line('');
            $this->line('  <fg=yellow>Register in AuthServiceProvider or boot():</>');
            foreach ($createdNames as $m) {
                $this->line("  <fg=gray>Gate::policy(\\App\\Models\\{$m}::class, \\App\\Policies\\{$m}Policy::class);</>");
            }
        }

        $this->line('');
        return self::SUCCESS;
    }

    private function detectOwnerColumn(array $columns): string
    {
        $ownerCols = ['user_id', 'author_id', 'owner_id', 'created_by'];
        foreach ($ownerCols as $col) {
            if (collect($columns)->pluck('name')->contains($col)) return $col;
        }
        return 'none (auth-only)';
    }
}

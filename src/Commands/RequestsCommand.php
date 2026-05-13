<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Generators\FormRequestGenerator;
use Julio\Capyrel\Schema\SchemaAnalyzer;
use Julio\Capyrel\Schema\RelationshipDetector;

class RequestsCommand extends Command
{
    protected $signature = 'model:requests
                            {model?         : Generate for a specific model only}
                            {--connection=  : Database connection to use}
                            {--force        : Overwrite existing request files}
                            {--dry-run      : Preview without writing}';

    protected $description = 'Generate Store and Update Form Request classes from column types and constraints';

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private RelationshipDetector $detector,
        private FormRequestGenerator $generator,
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

        $target    = $this->argument('model');
        $requestsPath = app_path('Http/Requests');

        if (!is_dir($requestsPath) && !$this->option('dry-run')) {
            mkdir($requestsPath, 0755, true);
        }

        $this->line('');
        $this->line('  <fg=cyan;options=bold>Generating Form Requests...</>');
        $this->line('');

        $created = 0;
        $skipped = 0;
        $skip    = ['migrations', 'cache', 'sessions', 'jobs', 'failed_jobs', 'job_batches', 'password_reset_tokens', 'cache_locks'];

        foreach ($this->analyzer->getTables() as $table) {
            if (in_array($table, $skip)) continue;
            if ($this->analyzer->isPivotTable($table)) continue;

            $modelName = Str::studly(Str::singular($table));

            if ($target && strtolower($modelName) !== strtolower($target)) continue;

            $columns  = $this->analyzer->getColumns($table);
            $fks      = $this->analyzer->getForeignKeys($table);
            $indexes  = $this->analyzer->getIndexes($table);

            if (empty($columns)) continue;

            foreach (['Store', 'Update'] as $type) {
                $filePath = "{$requestsPath}/{$type}{$modelName}Request.php";

                $code = $type === 'Store'
                    ? $this->generator->generateStore($modelName, $table, $columns, $fks, $indexes)
                    : $this->generator->generateUpdate($modelName, $table, $columns, $fks, $indexes);

                if ($this->option('dry-run')) {
                    $this->line("  <fg=cyan>[dry-run]</> Would create: <fg=white>Http/Requests/{$type}{$modelName}Request.php</>");
                    $created++;
                    continue;
                }

                if (file_exists($filePath) && !$this->option('force')) {
                    $this->line("  <fg=gray>~</> {$type}{$modelName}Request.php exists — use --force to overwrite");
                    $skipped++;
                    continue;
                }

                file_put_contents($filePath, $code);
                $this->line("  <fg=green>✔</> Created <fg=white>Http/Requests/{$type}{$modelName}Request.php</>");
                $created++;
            }
        }

        $this->line('');
        $this->line("  <fg=green;options=bold>Done.</> {$created} request(s) created · {$skipped} skipped");
        $this->line("  <fg=gray>Tip: Review and adjust the generated validation rules before use.</>");
        $this->line('');

        return self::SUCCESS;
    }
}

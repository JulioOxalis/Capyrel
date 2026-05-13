<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Generators\EnumGenerator;
use Julio\Capyrel\Schema\SchemaAnalyzer;

class EnumCommand extends Command
{
    protected $signature = 'model:enum
                            {model?         : Generate for a specific model only}
                            {--connection=  : Database connection to use}
                            {--force        : Overwrite existing files}
                            {--dry-run      : Preview without writing}';

    protected $description = 'Generate PHP 8.1 backed Enum classes for status/type columns with labels and colors';

    public function __construct(
        private SchemaAnalyzer $analyzer,
        private EnumGenerator  $generator,
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

        $target   = $this->argument('model');
        $enumPath = app_path('Enums');
        $skip     = ['migrations', 'cache', 'sessions', 'jobs', 'failed_jobs', 'job_batches', 'password_reset_tokens', 'cache_locks'];

        if (!is_dir($enumPath) && !$this->option('dry-run')) {
            mkdir($enumPath, 0755, true);
        }

        $this->line('');
        $this->line('  <fg=cyan;options=bold>Generating Enums...</>');
        $this->line('');

        $created = 0;
        $skipped = 0;

        foreach ($this->analyzer->getTables() as $table) {
            if (in_array($table, $skip)) continue;
            if ($this->analyzer->isPivotTable($table)) continue;

            $modelName  = Str::studly(Str::singular($table));
            if ($target && strtolower($modelName) !== strtolower($target)) continue;

            $columns    = $this->analyzer->getColumns($table);
            $enumCols   = $this->generator->detectEnumColumns($columns);

            if (empty($enumCols)) continue;

            $this->line("  <fg=white;options=bold>{$modelName}</>");

            foreach ($enumCols as $col) {
                $enumName = $modelName . Str::studly($col);
                $filePath = "{$enumPath}/{$enumName}.php";
                $code     = $this->generator->generate($modelName, $col);

                if ($this->option('dry-run')) {
                    $this->line("    <fg=cyan>[preview]</> App\\Enums\\{$enumName} <fg=gray>(from {$col} column)</>");
                    $created++;
                    continue;
                }

                if (file_exists($filePath) && !$this->option('force')) {
                    $this->line("    <fg=gray>~</> {$enumName}.php exists");
                    $skipped++;
                    continue;
                }

                file_put_contents($filePath, $code);
                $this->line("    <fg=green>✔</> Created App\\Enums\\{$enumName}");
                $this->line("    <fg=gray>  Add to model: protected \$casts = ['{$col}' => \\App\\Enums\\{$enumName}::class];</>");
                $created++;
            }

            $this->line('');
        }

        $this->line("  <fg=green;options=bold>Done.</> {$created} enum(s) created · {$skipped} skipped");
        $this->line('');

        return self::SUCCESS;
    }
}

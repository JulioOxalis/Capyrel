<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Generators\FactoryGenerator;
use Julio\Capyrel\Schema\SchemaAnalyzer;
use Julio\Capyrel\Schema\RelationshipDetector;

class FactoryCommand extends Command
{
    protected $signature = 'model:factory
                            {model?         : Generate for a specific model only}
                            {--connection=  : Database connection to use}
                            {--force        : Overwrite existing factory files}
                            {--dry-run      : Preview without writing}';

    protected $description = 'Generate Eloquent factories with smart Faker values from your schema';

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private RelationshipDetector $detector,
        private FactoryGenerator     $generator,
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
        $factoriesPath = database_path('factories');
        $skip          = ['migrations', 'cache', 'sessions', 'jobs', 'failed_jobs', 'job_batches', 'password_reset_tokens', 'cache_locks'];

        if (!is_dir($factoriesPath) && !$this->option('dry-run')) {
            mkdir($factoriesPath, 0755, true);
        }

        $this->line('');
        $this->line('  <fg=cyan;options=bold>Generating Factories...</>');
        $this->line('');

        $created = 0;
        $skipped = 0;

        foreach ($this->analyzer->getTables() as $table) {
            if (in_array($table, $skip)) continue;
            if ($this->analyzer->isPivotTable($table)) continue;

            $modelName = Str::studly(Str::singular($table));
            if ($target && strtolower($modelName) !== strtolower($target)) continue;

            $columns  = $this->analyzer->getColumns($table);
            $rels     = $relationships[$modelName] ?? [];
            $filePath = "{$factoriesPath}/{$modelName}Factory.php";

            if (empty($columns)) continue;

            $code = $this->generator->generate($modelName, $table, $columns, $rels);

            if ($this->option('dry-run')) {
                $this->line("  <fg=cyan>[preview]</> {$modelName}Factory");
                $this->previewFields($columns, $rels);
                $created++;
                continue;
            }

            if (file_exists($filePath) && !$this->option('force')) {
                $this->line("  <fg=gray>~</> {$modelName}Factory.php already exists — use --force to overwrite");
                $skipped++;
                continue;
            }

            file_put_contents($filePath, $code);
            $this->line("  <fg=green>✔</> Created <fg=white>database/factories/{$modelName}Factory.php</>");
            $created++;
        }

        $this->line('');
        $this->line("  <fg=green;options=bold>Done.</> {$created} factory(ies) created · {$skipped} skipped");
        $this->line("  <fg=gray>Run: {$modelName}::factory()->count(10)->create()</>");
        $this->line('');

        return self::SUCCESS;
    }

    private function previewFields(array $columns, array $rels): void
    {
        $skip = ['id', '_id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];
        foreach (array_slice($columns, 0, 5) as $col) {
            if (in_array($col['name'], $skip)) continue;
            $faker = "...";
            $this->line("    <fg=gray>{$col['name']} => {$faker}</>");
        }
    }
}

<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Generators\SeederGenerator;
use Julio\Capyrel\Schema\SchemaAnalyzer;
use Julio\Capyrel\Schema\RelationshipDetector;

class SeedCommand extends Command
{
    protected $signature = 'model:seed
                            {model?          : Generate seeder for a specific model}
                            {--connection=   : Database connection to use}
                            {--count=10      : Number of records to seed per model}
                            {--force         : Overwrite existing seeder files}
                            {--dry-run       : Preview without writing}
                            {--no-database   : Skip updating DatabaseSeeder}';

    protected $description = 'Generate seeders in foreign-key-correct order (parents before children)';

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private RelationshipDetector $detector,
        private SeederGenerator      $generator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $connection = $this->option('connection') ?? '';
        $count      = (int) $this->option('count');

        try {
            $this->analyzer->analyze($connection);
        } catch (\Throwable $e) {
            $this->error("Cannot read schema: {$e->getMessage()}");
            return self::FAILURE;
        }

        $relationships = $this->detector->detect();
        $sorted        = $this->generator->sortByDependency($relationships);
        $target        = $this->argument('model');
        $seedersPath   = database_path('seeders');
        $skip          = ['migrations', 'cache', 'sessions', 'jobs', 'failed_jobs', 'job_batches', 'password_reset_tokens', 'cache_locks'];

        $this->line('');
        $this->line('  <fg=cyan;options=bold>Generating Seeders...</>');
        $this->line('');
        $this->line("  <fg=gray>Seed order (FK-safe): " . implode(' → ', $sorted) . "</>");
        $this->line('');

        $created = 0;
        $skipped = 0;

        foreach ($sorted as $modelName) {
            if ($target && strtolower($modelName) !== strtolower($target)) continue;

            $table = Str::snake(Str::plural($modelName));
            if (in_array($table, $skip)) continue;
            if ($this->analyzer->isPivotTable($table)) continue;

            $filePath = "{$seedersPath}/{$modelName}Seeder.php";
            $code     = $this->generator->generate($modelName, $count);

            if ($this->option('dry-run')) {
                $this->line("  <fg=cyan>[preview]</> {$modelName}Seeder — {$count} records");
                $created++;
                continue;
            }

            if (file_exists($filePath) && !$this->option('force')) {
                $this->line("  <fg=gray>~</> {$modelName}Seeder.php exists — use --force");
                $skipped++;
                continue;
            }

            file_put_contents($filePath, $code);
            $this->line("  <fg=green>✔</> Created <fg=white>database/seeders/{$modelName}Seeder.php</>");
            $created++;
        }

        // Update DatabaseSeeder
        if (!$this->option('no-database') && !$this->option('dry-run') && !$target && $created > 0) {
            $dbSeederPath = "{$seedersPath}/DatabaseSeeder.php";
            $dbCode       = $this->generator->generateDatabaseSeeder($sorted);
            file_put_contents($dbSeederPath, $dbCode);
            $this->line("  <fg=green>✔</> Updated <fg=white>database/seeders/DatabaseSeeder.php</> <fg=gray>(FK-safe order)</>");
        }

        $this->line('');
        $this->line("  <fg=green;options=bold>Done.</> {$created} seeder(s) created · {$skipped} skipped");
        $this->line('  <fg=gray>Run: php artisan db:seed</>');
        $this->line('');

        return self::SUCCESS;
    }
}

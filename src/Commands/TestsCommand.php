<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Julio\Capyrel\Generators\RelationshipTestGenerator;
use Julio\Capyrel\Schema\SchemaAnalyzer;
use Julio\Capyrel\Schema\RelationshipDetector;

class TestsCommand extends Command
{
    protected $signature = 'model:tests
                            {model?         : Generate for a specific model only}
                            {--connection=  : Database connection to use}
                            {--force        : Overwrite existing test files}
                            {--dry-run      : Preview without writing}';

    protected $description = 'Generate Pest relationship tests for all detected models';

    public function __construct(
        private SchemaAnalyzer              $analyzer,
        private RelationshipDetector        $detector,
        private RelationshipTestGenerator   $generator,
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

        if ($target) {
            $relationships = array_filter($relationships, fn($k) => strtolower($k) === strtolower($target), ARRAY_FILTER_USE_KEY);
        }

        if (empty($relationships)) {
            $this->warn('No relationships detected.');
            return self::SUCCESS;
        }

        $testsPath = base_path('tests/Models');
        if (!is_dir($testsPath) && !$this->option('dry-run')) {
            mkdir($testsPath, 0755, true);
        }

        $this->line('');
        $this->line('  <fg=cyan;options=bold>Generating Relationship Tests...</>');
        $this->line('');

        $created = 0;
        $skipped = 0;

        foreach ($relationships as $modelName => $rels) {
            $filePath = "{$testsPath}/{$modelName}Test.php";
            $code     = $this->generator->generate($modelName, $rels);

            if ($this->option('dry-run')) {
                $this->line("  <fg=cyan>[dry-run]</> Would create: <fg=white>tests/Models/{$modelName}Test.php</>");
                $this->previewTests($modelName, $rels);
                $created++;
                continue;
            }

            if (file_exists($filePath) && !$this->option('force')) {
                $this->line("  <fg=gray>~</> {$modelName}Test.php exists — use --force to overwrite");
                $skipped++;
                continue;
            }

            file_put_contents($filePath, $code);
            $this->line("  <fg=green>✔</> Created <fg=white>tests/Models/{$modelName}Test.php</> (" . count($rels) . " tests)");
            $created++;
        }

        $this->line('');
        $this->line("  <fg=green;options=bold>Done.</> {$created} test file(s) created · {$skipped} skipped");
        $this->line("  <fg=gray>Run: php artisan test --filter=relationships</>");
        $this->line('');

        return self::SUCCESS;
    }

    private function previewTests(string $modelName, array $rels): void
    {
        foreach ($rels as $rel) {
            $class = match($rel['type']) {
                'hasOne'        => 'HasOne',
                'hasMany'       => 'HasMany',
                'belongsTo'     => 'BelongsTo',
                'belongsToMany' => 'BelongsToMany',
                default         => ucfirst($rel['type']),
            };
            $this->line("    <fg=gray>it('{$modelName}::{$rel['method']}() returns {$class}')</>");
        }
    }
}

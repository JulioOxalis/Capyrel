<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Julio\Capyrel\Generators\ApiResourceGenerator;
use Julio\Capyrel\Schema\SchemaAnalyzer;
use Julio\Capyrel\Schema\RelationshipDetector;

class ResourcesCommand extends Command
{
    protected $signature = 'model:resources
                            {model?         : Generate for a specific model only}
                            {--connection=  : Database connection to use}
                            {--force        : Overwrite existing resource files}
                            {--dry-run      : Preview without writing}';

    protected $description = 'Generate API Resource classes from detected relationships and columns';

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private RelationshipDetector $detector,
        private ApiResourceGenerator $generator,
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

        $resourcesPath = app_path('Http/Resources');
        if (!is_dir($resourcesPath) && !$this->option('dry-run')) {
            mkdir($resourcesPath, 0755, true);
        }

        $this->line('');
        $this->line('  <fg=cyan;options=bold>Generating API Resources...</>');
        $this->line('');

        $created  = 0;
        $skipped  = 0;

        foreach ($relationships as $modelName => $rels) {
            $filePath = "{$resourcesPath}/{$modelName}Resource.php";
            $columns  = $this->analyzer->getColumns($this->analyzer->getTables()[0] ?? '');

            // Find this model's table columns
            foreach ($this->analyzer->getTables() as $table) {
                $guessedModel = \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($table));
                if ($guessedModel === $modelName) {
                    $columns = $this->analyzer->getColumns($table);
                    break;
                }
            }

            $code = $this->generator->generate($modelName, $columns, $rels);

            if ($this->option('dry-run')) {
                $this->line("  <fg=cyan>[dry-run]</> Would create: <fg=white>Http/Resources/{$modelName}Resource.php</>");
                $created++;
                continue;
            }

            if (file_exists($filePath) && !$this->option('force')) {
                $this->line("  <fg=gray>~</> {$modelName}Resource.php already exists — use --force to overwrite");
                $skipped++;
                continue;
            }

            file_put_contents($filePath, $code);
            $this->line("  <fg=green>✔</> Created <fg=white>Http/Resources/{$modelName}Resource.php</>");
            $created++;
        }

        $this->line('');
        $this->line("  <fg=green;options=bold>Done.</> {$created} resource(s) created · {$skipped} skipped");
        $this->line("  <fg=gray>Tip: Resources use whenLoaded() on all relationships — no N+1 possible.</>");
        $this->line('');

        return self::SUCCESS;
    }
}

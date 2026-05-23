<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Generators\OpenApiGenerator;
use Julio\Capyrel\Schema\RelationshipDetector;
use Julio\Capyrel\Schema\SchemaAnalyzer;

class OpenApiCommand extends Command
{
    protected $signature = 'capyrel:openapi
                            {--connection=  : Database connection}
                            {--output=      : Output path (default: storage/app/openapi.yaml)}
                            {--force        : Overwrite existing file}';

    protected $description = 'Generate a complete OpenAPI 3.1 YAML specification from your schema';

    public function __construct(
        private SchemaAnalyzer      $analyzer,
        private RelationshipDetector $detector,
        private OpenApiGenerator    $generator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $this->analyzer->analyze($this->option('connection') ?? '');
        } catch (\Throwable $e) {
            $this->error("Cannot read schema: {$e->getMessage()}");
            return self::FAILURE;
        }

        $all     = $this->detector->detect();
        $models  = [];

        foreach ($all as $modelName => $rels) {
            $columns = [];
            foreach ($this->analyzer->getTables() as $table) {
                if (Str::studly(Str::singular($table)) === $modelName) {
                    $columns = $this->analyzer->getColumns($table);
                    break;
                }
            }
            $models[$modelName] = ['columns' => $columns, 'relationships' => $rels];
        }

        $output = $this->option('output') ?: storage_path('app/openapi.yaml');
        $dir    = dirname($output);

        if (!is_dir($dir)) mkdir($dir, 0755, true);

        if (file_exists($output) && !$this->option('force')) {
            $this->warn("File already exists: {$output}. Use --force to overwrite.");
            return self::SUCCESS;
        }

        $yaml = $this->generator->generate(config('app.name'), config('app.url'), $models);
        file_put_contents($output, $yaml);

        $this->info("✔ OpenAPI spec written to: {$output}");
        $this->line('  Models: ' . implode(', ', array_keys($models)));
        $this->line('  Serve at /api/docs by adding a route that returns file contents.');

        return self::SUCCESS;
    }
}

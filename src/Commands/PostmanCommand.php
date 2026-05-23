<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Generators\PostmanGenerator;
use Julio\Capyrel\Schema\RelationshipDetector;
use Julio\Capyrel\Schema\SchemaAnalyzer;

class PostmanCommand extends Command
{
    protected $signature = 'capyrel:postman
                            {--connection=  : Database connection}
                            {--output=      : Output path (default: storage/app/postman_collection.json)}
                            {--force        : Overwrite existing file}';

    protected $description = 'Generate a Postman/Insomnia collection JSON for all API endpoints';

    public function __construct(
        private SchemaAnalyzer      $analyzer,
        private RelationshipDetector $detector,
        private PostmanGenerator    $generator,
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

        $all    = $this->detector->detect();
        $models = [];

        foreach ($all as $modelName => $rels) {
            $columns = $this->columnsFor($modelName);
            if (!empty($columns)) {
                $models[$modelName] = ['columns' => $columns, 'relationships' => $rels];
            }
        }

        $output = $this->option('output') ?: storage_path('app/postman_collection.json');
        if (!is_dir(dirname($output))) mkdir(dirname($output), 0755, true);

        if (file_exists($output) && !$this->option('force')) {
            $this->warn("File exists: {$output}. Use --force to overwrite.");
            return self::SUCCESS;
        }

        $json = $this->generator->generate(config('app.name'), config('app.url'), $models);
        file_put_contents($output, $json);

        $this->info("✔ Postman collection written to: {$output}");
        $this->line('  Import into Postman: File → Import → select the file above.');
        $this->line('  Set {{base_url}} = ' . config('app.url') . ' in your environment.');

        return self::SUCCESS;
    }

    private function columnsFor(string $modelName): array
    {
        foreach ($this->analyzer->getTables() as $table) {
            if (Str::studly(Str::singular($table)) === $modelName) {
                return $this->analyzer->getColumns($table);
            }
        }
        return [];
    }
}

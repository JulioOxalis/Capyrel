<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Generators\GraphQLGenerator;
use Julio\Capyrel\Schema\RelationshipDetector;
use Julio\Capyrel\Schema\SchemaAnalyzer;

class GraphQLCommand extends Command
{
    protected $signature = 'capyrel:graphql
                            {--connection=  : Database connection}
                            {--output=      : Output file (default: graphql/schema.graphql)}
                            {--force        : Overwrite existing file}
                            {--append       : Append to existing schema instead of overwriting}';

    protected $description = 'Generate a Lighthouse PHP GraphQL schema from your database schema';

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private RelationshipDetector $detector,
        private GraphQLGenerator     $generator,
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

        $output = $this->option('output') ?: base_path('graphql/schema.graphql');
        $dir    = dirname($output);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        if (file_exists($output) && !$this->option('force') && !$this->option('append')) {
            $this->warn("File exists: {$output}. Use --force to overwrite or --append to add to it.");
            return self::SUCCESS;
        }

        $schema = $this->generator->generateSchema($models);

        if ($this->option('append') && file_exists($output)) {
            file_put_contents($output, "\n\n" . $schema, FILE_APPEND);
            $this->info("✔ GraphQL schema appended to: {$output}");
        } else {
            file_put_contents($output, $schema);
            $this->info("✔ GraphQL schema written to: {$output}");
        }

        $this->line('  Models: ' . implode(', ', array_keys($models)));
        $this->line('  Requires: nuwave/lighthouse (composer require nuwave/lighthouse)');

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

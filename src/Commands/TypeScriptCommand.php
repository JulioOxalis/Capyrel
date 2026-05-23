<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Generators\AxiosClientGenerator;
use Julio\Capyrel\Generators\TypeScriptGenerator;
use Julio\Capyrel\Schema\RelationshipDetector;
use Julio\Capyrel\Schema\SchemaAnalyzer;

class TypeScriptCommand extends Command
{
    protected $signature = 'capyrel:typescript
                            {--connection=     : Database connection}
                            {--out=resources/ts : Output directory for generated files}
                            {--no-axios         : Skip generating Axios service classes}
                            {--force            : Overwrite existing files}';

    protected $description = 'Generate TypeScript interfaces + typed Axios service classes from your schema';

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private RelationshipDetector $detector,
        private TypeScriptGenerator  $tsGen,
        private AxiosClientGenerator $axiosGen,
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

        $all      = $this->detector->detect();
        $outDir   = base_path($this->option('out'));
        $modelsDir   = "{$outDir}/models";
        $servicesDir = "{$outDir}/services";

        foreach ([$modelsDir, $servicesDir] as $dir) {
            if (!is_dir($dir)) mkdir($dir, 0755, true);
        }

        $modelNames = [];
        $written    = 0;

        foreach ($all as $modelName => $rels) {
            $columns = $this->columnsFor($modelName);
            if (empty($columns)) continue;

            $modelNames[] = $modelName;

            // Model interface
            $ifPath = "{$modelsDir}/{$modelName}.ts";
            if (!file_exists($ifPath) || $this->option('force')) {
                file_put_contents($ifPath, $this->tsGen->generateInterface($modelName, $columns, $rels));
                $this->line("  <fg=green>✔</> <fg=white>{$modelName}.ts</>");
                $written++;
            } else {
                $this->line("  <fg=gray>~ {$modelName}.ts (exists)</>");
            }

            // Axios service
            if (!$this->option('no-axios')) {
                $svcPath = "{$servicesDir}/{$modelName}Service.ts";
                if (!file_exists($svcPath) || $this->option('force')) {
                    file_put_contents($svcPath, $this->axiosGen->generate($modelName, $columns, $rels));
                    $this->line("  <fg=green>✔</> <fg=white>{$modelName}Service.ts</>");
                    $written++;
                } else {
                    $this->line("  <fg=gray>~ {$modelName}Service.ts (exists)</>");
                }
            }
        }

        // Barrel index
        $indexPath = "{$modelsDir}/index.ts";
        file_put_contents($indexPath, $this->tsGen->generateBarrel($modelNames));
        $this->line("  <fg=green>✔</> <fg=white>models/index.ts</> (barrel re-export)");
        $written++;

        $this->line('');
        $this->info("✔ TypeScript generation complete. {$written} file(s) written to {$outDir}/");

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

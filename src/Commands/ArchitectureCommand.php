<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Generators\RepositoryGenerator;
use Julio\Capyrel\Generators\ServiceGenerator;
use Julio\Capyrel\Schema\RelationshipDetector;
use Julio\Capyrel\Schema\SchemaAnalyzer;

/**
 * Generates the clean-architecture stack for a model:
 *   - Repository interface + Eloquent implementation
 *   - Service class
 *
 * Use --only=repository|service to generate a subset.
 */
class ArchitectureCommand extends Command
{
    protected $signature = 'model:architecture
                            {model?          : Model name (e.g. Post) — omit to scaffold all}
                            {--only=         : Comma-separated subset: repository,service}
                            {--connection=   : Database connection}
                            {--force         : Overwrite existing files}
                            {--dry-run       : Preview without writing}';

    protected $description = 'Generate Repository interface, Eloquent implementation, and Service class for a model';

    public function __construct(
        private SchemaAnalyzer      $analyzer,
        private RelationshipDetector $detector,
        private RepositoryGenerator $repoGen,
        private ServiceGenerator    $serviceGen,
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

        $all          = $this->detector->detect();
        $targetModel  = $this->argument('model') ? Str::studly($this->argument('model')) : null;
        $only         = array_filter(explode(',', $this->option('only') ?? 'repository,service'));
        $doRepository = empty($only) || in_array('repository', $only);
        $doService    = empty($only) || in_array('service', $only);

        if ($targetModel) {
            $all = array_filter($all, fn($k) => strtolower($k) === strtolower($targetModel), ARRAY_FILTER_USE_KEY);
        }

        if (empty($all)) {
            $this->warn('No models found. Run model:scaffold first to detect relationships.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  <fg=cyan;options=bold>Architecture Generator</>');
        $this->line('');

        $total = 0;

        foreach ($all as $modelName => $rels) {
            $columns = $this->columnsFor($modelName);
            if (empty($columns)) continue;

            $this->line("  <fg=cyan>■</> <fg=white;options=bold>{$modelName}</>");

            if ($doRepository) {
                $total += $this->writeRepository($modelName, $columns, $rels);
            }

            if ($doService) {
                $total += $this->writeService($modelName, $columns, $rels);
            }
        }

        if (!$this->option('dry-run')) {
            $this->line('');
            $this->line("  <fg=green;options=bold>✔ Done.</> {$total} file(s) written.");
            $this->line('');
            $this->line('  <fg=gray>Register bindings in AppServiceProvider::register():</>');
            $this->line('  <fg=gray>  $this->app->bind(PostRepositoryInterface::class, PostRepository::class);</>');
            $this->line('');
        }

        return self::SUCCESS;
    }

    // ── Writers ───────────────────────────────────────────────────────────────

    private function writeRepository(string $modelName, array $columns, array $rels): int
    {
        $written = 0;

        // Interface
        $interfaceDir  = app_path('Repositories/Contracts');
        $interfacePath = "{$interfaceDir}/{$modelName}RepositoryInterface.php";
        if (!is_dir($interfaceDir)) mkdir($interfaceDir, 0755, true);

        if ($this->option('dry-run')) {
            $this->line("    <fg=cyan>[dry-run]</> Would create: app/Repositories/Contracts/{$modelName}RepositoryInterface.php");
        } elseif (!file_exists($interfacePath) || $this->option('force')) {
            file_put_contents($interfacePath, $this->repoGen->generateInterface($modelName, $columns, $rels));
            $this->line("    <fg=green>✔</> Created <fg=white>app/Repositories/Contracts/{$modelName}RepositoryInterface.php</>");
            $written++;
        } else {
            $this->line("    <fg=gray>~ {$modelName}RepositoryInterface.php already exists</>");
        }

        // Implementation
        $implDir  = app_path('Repositories');
        $implPath = "{$implDir}/{$modelName}Repository.php";
        if (!is_dir($implDir)) mkdir($implDir, 0755, true);

        if ($this->option('dry-run')) {
            $this->line("    <fg=cyan>[dry-run]</> Would create: app/Repositories/{$modelName}Repository.php");
        } elseif (!file_exists($implPath) || $this->option('force')) {
            file_put_contents($implPath, $this->repoGen->generateImplementation($modelName, $columns, $rels));
            $this->line("    <fg=green>✔</> Created <fg=white>app/Repositories/{$modelName}Repository.php</>");
            $written++;
        } else {
            $this->line("    <fg=gray>~ {$modelName}Repository.php already exists</>");
        }

        return $written;
    }

    private function writeService(string $modelName, array $columns, array $rels): int
    {
        $serviceDir  = app_path('Services');
        $servicePath = "{$serviceDir}/{$modelName}Service.php";
        if (!is_dir($serviceDir)) mkdir($serviceDir, 0755, true);

        if ($this->option('dry-run')) {
            $this->line("    <fg=cyan>[dry-run]</> Would create: app/Services/{$modelName}Service.php");
            return 0;
        }

        if (!file_exists($servicePath) || $this->option('force')) {
            file_put_contents($servicePath, $this->serviceGen->generate($modelName, $columns, $rels));
            $this->line("    <fg=green>✔</> Created <fg=white>app/Services/{$modelName}Service.php</>");
            return 1;
        }

        $this->line("    <fg=gray>~ {$modelName}Service.php already exists</>");
        return 0;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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

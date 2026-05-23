<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Generators\PermissionMatrixGenerator;
use Julio\Capyrel\Schema\RelationshipDetector;
use Julio\Capyrel\Schema\SchemaAnalyzer;

class PermissionsCommand extends Command
{
    protected $signature = 'capyrel:permissions
                            {--connection=  : Database connection}
                            {--force        : Overwrite existing files}
                            {--dry-run      : Preview without writing}
                            {--no-policies  : Skip policy file generation}
                            {--no-seeder    : Skip permission seeder generation}';

    protected $description = 'Generate permission matrix seeder + Policy classes for all models';

    public function __construct(
        private SchemaAnalyzer            $analyzer,
        private RelationshipDetector      $detector,
        private PermissionMatrixGenerator $generator,
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

        $all        = $this->detector->detect();
        $modelNames = array_keys($all);
        $written    = 0;
        $dry        = $this->option('dry-run');

        // ── Policies ──────────────────────────────────────────────────────────

        if (!$this->option('no-policies')) {
            $policyDir = app_path('Policies');
            if (!$dry && !is_dir($policyDir)) mkdir($policyDir, 0755, true);

            foreach ($all as $modelName => $rels) {
                $columns    = $this->columnsFor($modelName);
                $policyPath = "{$policyDir}/{$modelName}Policy.php";

                if ($dry) {
                    $this->line("  <fg=cyan>[dry-run]</> Would create: app/Policies/{$modelName}Policy.php");
                    continue;
                }

                if (!file_exists($policyPath) || $this->option('force')) {
                    file_put_contents($policyPath, $this->generator->generatePolicy($modelName, $columns));
                    $this->line("  <fg=green>✔</> app/Policies/{$modelName}Policy.php");
                    $written++;
                } else {
                    $this->line("  <fg=gray>~ {$modelName}Policy.php (exists)</>");
                }
            }
        }

        // ── Permission Seeder ─────────────────────────────────────────────────

        if (!$this->option('no-seeder')) {
            $seederPath = database_path('seeders/PermissionSeeder.php');

            if ($dry) {
                $this->line("  <fg=cyan>[dry-run]</> Would create: database/seeders/PermissionSeeder.php");
            } elseif (!file_exists($seederPath) || $this->option('force')) {
                file_put_contents($seederPath, $this->generator->generatePermissionSeeder($modelNames));
                $this->line("  <fg=green>✔</> database/seeders/PermissionSeeder.php");
                $written++;
            } else {
                $this->line("  <fg=gray>~ PermissionSeeder.php (exists)</>");
            }
        }

        // ── Gate registration hint ─────────────────────────────────────────────

        if (!$dry) {
            $regPath = base_path('_capyrel_policy_registration.php');
            file_put_contents($regPath, $this->generator->generatePolicyServiceProviderRegistration($modelNames));
            $this->line("  <fg=yellow>Add policy registrations from:</> <fg=white>_capyrel_policy_registration.php</> to AppServiceProvider::boot()");
        }

        $this->line('');
        if (!$dry) {
            $this->info("✔ Permissions generation complete. {$written} file(s) written.");
            if (!$this->option('no-seeder')) {
                $this->line('  Run: php artisan db:seed --class=PermissionSeeder  to seed roles & permissions.');
            }
        }

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

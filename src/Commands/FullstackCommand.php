<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;

/**
 * The ONE command that does everything in the correct order.
 *
 * Runs the full capyrel stack:
 *   1. scaffold     → models, controllers, blade, routes
 *   2. resources    → API resources
 *   3. requests     → form requests
 *   4. factory      → Faker factories
 *   5. policy       → authorization policies
 *   6. seed         → seeders in FK-safe order
 *   7. optimize     → fillable, casts, scopes
 *   8. enum         → PHP 8.1 enums
 *   9. events       → events + observers
 *  10. tests        → Pest relationship tests
 *  11. livewire     → Livewire components (if installed)
 *  12. audit        → full health report
 */
class FullstackCommand extends Command
{
    protected $signature = 'capyrel:fullstack
                            {--connection=    : Database connection}
                            {--dry-run        : Preview every step without writing}
                            {--force          : Skip all confirmations}
                            {--architecture   : Also run model:architecture (repository+service)}
                            {--skip=          : Comma-separated steps to skip}';

    protected $description = 'Run the complete capyrel scaffold in the correct order — from schema to production-ready code';

    private array $steps = [
        // Core scaffolding (models enhanced, controllers written, blade index pages, routes)
        'scaffold'      => ['model:scaffold',      'Scaffold models (with casts/scopes/accessors), controllers, blade index, routes'],
        // Per-model generators
        'resources'     => ['model:resources',     'Generate typed API Resources'],
        'requests'      => ['model:requests',      'Generate Form Requests (with policy authorize)'],
        'factory'       => ['model:factory',       'Generate Faker Factories'],
        'policy'        => ['model:policy',        'Generate Authorization Policies'],
        'seed'          => ['model:seed',          'Generate Seeders (FK-safe order)'],
        'optimize'      => ['model:optimize',      'Ensure fillable, casts, scopes are complete'],
        'enum'          => ['model:enum',          'Generate PHP 8.1 Enums for ENUM columns'],
        'events'        => ['model:events',        'Generate Events + Observers'],
        'tests'         => ['model:tests',         'Generate Pest relationship + feature tests'],
        'livewire'      => ['model:livewire',      'Generate Livewire Components (if installed)'],
        // Audit always last
        'audit'         => ['capyrel:audit',       'Full health report (N+1, missing indexes, etc.)'],
    ];

    public function handle(): int
    {
        $this->line('');
        $this->line('  <fg=cyan;options=bold>╔══════════════════════════════════════╗</>');
        $this->line('  <fg=cyan;options=bold>║   CAPYREL FULLSTACK — ALL 12 STEPS   ║</>');
        $this->line('  <fg=cyan;options=bold>╚══════════════════════════════════════╝</>');
        $this->line('');

        $skip       = array_filter(explode(',', $this->option('skip') ?? ''));
        $connection = $this->option('connection') ?? '';
        $dryRun     = $this->option('dry-run');
        $force      = $this->option('force');
        $completed  = 0;
        $skipped    = 0;

        foreach ($this->steps as $key => [$artisan, $description]) {
            if (in_array($key, $skip)) {
                $this->line("  <fg=gray>⊘ Skipping [{$key}]: {$description}</>");
                $skipped++;
                continue;
            }

            $this->line('');
            $this->line("  <fg=cyan>[" . ($completed + $skipped + 1) . "/" . count($this->steps) . "]</> <fg=white;options=bold>{$description}</>");
            $this->line('');

            $args = array_filter([
                '--connection' => $connection ?: null,
                '--dry-run'    => $dryRun ?: null,
                '--force'      => $force ?: null,
            ]);

            try {
                $this->call($artisan, $args);
                $completed++;
            } catch (\Throwable $e) {
                $this->line("  <fg=yellow>⚠ {$artisan} skipped: {$e->getMessage()}</>");
                $skipped++;
            }
        }

        $this->line('');
        // Optional: architecture layer (repository + service)
        if ($this->option('architecture') && !in_array('architecture', $skip)) {
            $this->line('');
            $this->line('  <fg=cyan>[arch]</> <fg=white;options=bold>Generate Repository + Service layer</>');
            $this->line('');
            try {
                $this->call('model:architecture', array_filter([
                    '--connection' => $connection ?: null,
                    '--dry-run'    => $dryRun ?: null,
                    '--force'      => $force ?: null,
                ]));
                $completed++;
            } catch (\Throwable $e) {
                $this->line("  <fg=yellow>⚠ model:architecture skipped: {$e->getMessage()}</>");
            }
        }

        $this->line('');
        $this->line('  <fg=green;options=bold>✔ Fullstack complete.</>');
        $this->line("  <fg=gray>  {$completed} step(s) completed · {$skipped} skipped</>");
        $this->line('');
        $this->line('  <fg=gray>Next steps:</>');
        $this->line('  <fg=gray>  • Review generated controllers — add domain logic as needed</>');
        $this->line('  <fg=gray>  • Run: php artisan storage:link (for file upload support)</>');
        $this->line('  <fg=gray>  • Run: php artisan test to verify generated tests</>');
        $this->line('  <fg=gray>  • See CAPYREL_AUDIT.md for the full health report</>');
        $this->line('');

        return self::SUCCESS;
    }
}

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
                            {--connection=  : Database connection}
                            {--dry-run      : Preview every step without writing}
                            {--force        : Skip all confirmations}
                            {--skip=        : Comma-separated steps to skip: scaffold,resources,requests,factory,policy,seed,optimize,enum,events,tests,livewire,audit}';

    protected $description = 'Run the complete capyrel scaffold in the correct order — from schema to production-ready code';

    private array $steps = [
        'scaffold'  => ['model:scaffold',  'Scaffold models, controllers, blade pages, routes'],
        'resources' => ['model:resources', 'Generate API Resources'],
        'requests'  => ['model:requests',  'Generate Form Requests'],
        'factory'   => ['model:factory',   'Generate Faker Factories'],
        'policy'    => ['model:policy',    'Generate Authorization Policies'],
        'seed'      => ['model:seed',      'Generate Seeders (FK-safe order)'],
        'optimize'  => ['model:optimize',  'Add fillable, casts, scopes to models'],
        'enum'      => ['model:enum',      'Generate PHP 8.1 Enums'],
        'events'    => ['model:events',    'Generate Events + Observers'],
        'tests'     => ['model:tests',     'Generate Pest Relationship Tests'],
        'livewire'  => ['model:livewire',  'Generate Livewire Components'],
        'audit'     => ['capyrel:audit',   'Generate Health Report'],
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
        $this->line('  <fg=green;options=bold>✔ Fullstack complete.</>');
        $this->line("  <fg=gray>  {$completed} step(s) completed · {$skipped} skipped</>");
        $this->line('');
        $this->line('  <fg=gray>Review CAPYREL_AUDIT.md for the full health report.</> ');
        $this->line('');

        return self::SUCCESS;
    }
}

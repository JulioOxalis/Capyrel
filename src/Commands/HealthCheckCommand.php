<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Julio\Capyrel\Generators\HealthCheckGenerator;

class HealthCheckCommand extends Command
{
    protected $signature = 'capyrel:health-controller
                            {--force : Overwrite existing file}';

    protected $description = 'Generate a production-grade /health endpoint controller';

    public function __construct(private HealthCheckGenerator $generator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = app_path('Http/Controllers/HealthController.php');

        if (file_exists($path) && !$this->option('force')) {
            $this->warn("HealthController.php already exists. Use --force to overwrite.");
            return self::SUCCESS;
        }

        file_put_contents($path, $this->generator->generateController());
        $this->info("✔ Created app/Http/Controllers/HealthController.php");

        // Print route hint
        $routeHint = $this->generator->generateRoutes();
        $this->line('');
        $this->line('  Add to routes/web.php:');
        $this->line('  <fg=gray>' . str_replace("\n", "\n  ", trim($routeHint)) . '</>');
        $this->line('');

        return self::SUCCESS;
    }
}

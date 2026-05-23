<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Generators\BroadcastingGenerator;
use Julio\Capyrel\Schema\RelationshipDetector;
use Julio\Capyrel\Schema\SchemaAnalyzer;

class BroadcastingCommand extends Command
{
    protected $signature = 'model:broadcast
                            {model?         : Model name — omit for all}
                            {--connection=  : Database connection}
                            {--force        : Overwrite existing files}
                            {--dry-run      : Preview without writing}
                            {--frontend     : Also generate TypeScript channel listeners}';

    protected $description = 'Generate ShouldBroadcast events + channel classes for real-time model updates';

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private RelationshipDetector $detector,
        private BroadcastingGenerator $generator,
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

        $all         = $this->detector->detect();
        $targetModel = $this->argument('model') ? Str::studly($this->argument('model')) : null;

        if ($targetModel) {
            $all = array_filter($all, fn($k) => strtolower($k) === strtolower($targetModel), ARRAY_FILTER_USE_KEY);
        }

        $written = 0;

        foreach (array_keys($all) as $modelName) {
            $this->line("  <fg=cyan>■</> <fg=white;options=bold>{$modelName}</>");

            foreach (['created', 'updated', 'deleted'] as $action) {
                $path = app_path("Events/{$modelName}" . Str::studly($action) . ".php");
                if ($this->option('dry-run')) {
                    $this->line("    <fg=cyan>[dry-run]</> Would create: app/Events/{$modelName}" . Str::studly($action) . ".php");
                    continue;
                }
                if (!file_exists($path) || $this->option('force')) {
                    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
                    file_put_contents($path, $this->generator->generateEvent($modelName, $action));
                    $this->line("    <fg=green>✔</> app/Events/{$modelName}" . Str::studly($action) . ".php");
                    $written++;
                } else {
                    $this->line("    <fg=gray>~ {$modelName}" . Str::studly($action) . ".php exists</>");
                }
            }

            // Channel class
            $chPath = app_path("Broadcasting/{$modelName}Channel.php");
            if (!$this->option('dry-run') && (!file_exists($chPath) || $this->option('force'))) {
                if (!is_dir(dirname($chPath))) mkdir(dirname($chPath), 0755, true);
                file_put_contents($chPath, $this->generator->generateChannel($modelName));
                $this->line("    <fg=green>✔</> app/Broadcasting/{$modelName}Channel.php");
                $written++;
            }

            // TypeScript listener
            if ($this->option('frontend')) {
                $tsPath = resource_path("ts/broadcasting/{$modelName}Channel.ts");
                if (!is_dir(dirname($tsPath))) mkdir(dirname($tsPath), 0755, true);
                file_put_contents($tsPath, $this->generator->generateFrontendListener($modelName));
                $this->line("    <fg=green>✔</> resources/ts/broadcasting/{$modelName}Channel.ts");
                $written++;
            }
        }

        // channels.php registration block
        if (!$this->option('dry-run')) {
            $chanPath = base_path('_capyrel_channels_registration.php');
            file_put_contents($chanPath, $this->generator->generateChannelsRegistration(array_keys($all)));
            $this->line('');
            $this->line("  <fg=yellow>Add channel registrations from:<fg=white> _capyrel_channels_registration.php</> to routes/channels.php");
        }

        $this->line('');
        $this->info("✔ Broadcasting generation complete. {$written} file(s) written.");

        return self::SUCCESS;
    }
}

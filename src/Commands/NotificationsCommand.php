<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Generators\NotificationGenerator;
use Julio\Capyrel\Schema\RelationshipDetector;
use Julio\Capyrel\Schema\SchemaAnalyzer;

class NotificationsCommand extends Command
{
    protected $signature = 'model:notifications
                            {model?             : Target model — omit for all}
                            {--actions=created,updated,deleted : Comma-separated lifecycle events}
                            {--connection=      : Database connection}
                            {--force            : Overwrite existing files}
                            {--dry-run          : Preview without writing}';

    protected $description = 'Generate mail + database notification classes per model lifecycle event';

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private RelationshipDetector $detector,
        private NotificationGenerator $generator,
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
        $actions     = array_filter(explode(',', $this->option('actions') ?? 'created,updated,deleted'));
        $dry         = $this->option('dry-run');

        if ($targetModel) {
            $all = array_filter($all, fn($k) => strtolower($k) === strtolower($targetModel), ARRAY_FILTER_USE_KEY);
        }

        $written = 0;

        foreach (array_keys($all) as $modelName) {
            $this->line("  <fg=cyan>■</> <fg=white;options=bold>{$modelName}</>");

            foreach ($actions as $action) {
                $notifName = $modelName . Str::studly($action) . 'Notification';
                $notifPath = app_path("Notifications/{$notifName}.php");
                $mailPath  = resource_path('views/emails/' . Str::kebab(Str::plural($modelName)) . "/{$action}.blade.php");

                if ($dry) {
                    $this->line("    <fg=cyan>[dry-run]</> Would create: app/Notifications/{$notifName}.php");
                    continue;
                }

                if (!is_dir(dirname($notifPath))) mkdir(dirname($notifPath), 0755, true);
                if (!is_dir(dirname($mailPath)))  mkdir(dirname($mailPath),  0755, true);

                if (!file_exists($notifPath) || $this->option('force')) {
                    file_put_contents($notifPath, $this->generator->generateNotification($modelName, $action));
                    $this->line("    <fg=green>✔</> app/Notifications/{$notifName}.php");
                    $written++;
                } else {
                    $this->line("    <fg=gray>~ {$notifName}.php (exists)</>");
                }

                if (!file_exists($mailPath) || $this->option('force')) {
                    file_put_contents($mailPath, $this->generator->generateMarkdownMail($modelName, $action));
                    $written++;
                }
            }
        }

        $this->line('');
        if (!$dry) $this->info("✔ Notifications generated. {$written} file(s) written.");

        return self::SUCCESS;
    }
}

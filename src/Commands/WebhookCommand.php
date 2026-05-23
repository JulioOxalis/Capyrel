<?php

namespace Julio\Capyrel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Julio\Capyrel\Generators\WebhookGenerator;
use Julio\Capyrel\Schema\RelationshipDetector;
use Julio\Capyrel\Schema\SchemaAnalyzer;

class WebhookCommand extends Command
{
    protected $signature = 'capyrel:webhooks
                            {model?         : Target a specific model — omit for shared infrastructure}
                            {--connection=  : Database connection}
                            {--force        : Overwrite existing files}
                            {--dry-run      : Preview without writing}';

    protected $description = 'Generate outbound webhook infrastructure (subscription model, signing job, dispatchers)';

    public function __construct(
        private SchemaAnalyzer       $analyzer,
        private RelationshipDetector $detector,
        private WebhookGenerator     $generator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $written = 0;
        $dry     = $this->option('dry-run');

        // ── Shared infrastructure (run once) ──────────────────────────────────

        $infra = [
            'app/Models/WebhookSubscription.php'   => fn() => $this->generator->generateSubscriptionModel(),
            'app/Jobs/SendWebhookJob.php'           => fn() => $this->generator->generateJob(),
        ];

        foreach ($infra as $path => $generator) {
            $full = base_path($path);
            if ($dry) {
                $this->line("  <fg=cyan>[dry-run]</> Would create: {$path}");
                continue;
            }
            if (!is_dir(dirname($full))) mkdir(dirname($full), 0755, true);
            if (!file_exists($full) || $this->option('force')) {
                file_put_contents($full, $generator());
                $this->line("  <fg=green>✔</> {$path}");
                $written++;
            } else {
                $this->line("  <fg=gray>~ {$path} (exists)</>");
            }
        }

        // Migration
        $migPath = database_path('migrations/' . now()->format('Y_m_d_His') . '_create_webhook_subscriptions_table.php');
        $existing = glob(database_path('migrations/*_create_webhook_subscriptions_table.php'));
        if (empty($existing)) {
            if (!$dry) {
                file_put_contents($migPath, $this->generator->generateMigration());
                $this->line("  <fg=green>✔</> " . basename($migPath));
                $written++;
            } else {
                $this->line("  <fg=cyan>[dry-run]</> Would create webhook_subscriptions migration");
            }
        } else {
            $this->line("  <fg=gray>~ Webhook migration already exists</>");
        }

        // ── Per-model dispatchers ─────────────────────────────────────────────

        try { $this->analyzer->analyze($this->option('connection') ?? ''); } catch (\Throwable $e) {}

        $all         = $this->detector->detect();
        $targetModel = $this->argument('model') ? Str::studly($this->argument('model')) : null;
        if ($targetModel) {
            $all = array_filter($all, fn($k) => strtolower($k) === strtolower($targetModel), ARRAY_FILTER_USE_KEY);
        }

        foreach (array_keys($all) as $modelName) {
            $dispPath = app_path("Webhooks/{$modelName}Webhook.php");
            if ($dry) {
                $this->line("  <fg=cyan>[dry-run]</> Would create: app/Webhooks/{$modelName}Webhook.php");
                continue;
            }
            if (!is_dir(dirname($dispPath))) mkdir(dirname($dispPath), 0755, true);
            if (!file_exists($dispPath) || $this->option('force')) {
                file_put_contents($dispPath, $this->generator->generateDispatcher($modelName));
                $this->line("  <fg=green>✔</> app/Webhooks/{$modelName}Webhook.php");
                $written++;
            } else {
                $this->line("  <fg=gray>~ {$modelName}Webhook.php (exists)</>");
            }
        }

        $this->line('');
        if (!$dry) {
            $this->info("✔ Webhook infrastructure generated. {$written} file(s) written.");
            $this->line('  Run: php artisan migrate  to create the webhook_subscriptions table.');
            $this->line('  Call WebhookSubscription::dispatch() from your model observers.');
        }

        return self::SUCCESS;
    }
}

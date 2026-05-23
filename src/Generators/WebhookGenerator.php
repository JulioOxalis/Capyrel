<?php

namespace Julio\Capyrel\Generators;

use Illuminate\Support\Str;

/**
 * Generates an outbound webhook system:
 *   App\Models\WebhookSubscription         — stores subscriber endpoints
 *   App\Jobs\SendWebhookJob                 — queued delivery with HMAC signing & retries
 *   App\Http\Controllers\WebhookController — CRUD for subscriptions
 *   migrations for webhook_subscriptions table
 *
 * Each model that uses webhooks gets event dispatchers injected via its observer.
 */
class WebhookGenerator
{
    public function generateSubscriptionModel(): string
    {
        return <<<PHP
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stores outbound webhook endpoints subscribed to model events.
 *
 * @property int    \$id
 * @property string \$url          Endpoint URL
 * @property array  \$events       List of event names this subscriber wants
 * @property string \$secret       HMAC signing secret (never expose)
 * @property bool   \$is_active
 * @property int    \$failure_count
 * @property \Carbon\Carbon|null \$last_success_at
 */
class WebhookSubscription extends Model
{
    protected \$fillable = [
        'url', 'events', 'secret', 'is_active', 'description',
    ];

    protected \$casts = [
        'events'         => 'array',
        'is_active'      => 'boolean',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    protected \$hidden = ['secret'];

    public function scopeActive(\$query): \$query
    {
        return \$query->where('is_active', true);
    }

    public function scopeForEvent(\$query, string \$event): \$query
    {
        return \$query->where(fn(\$q) =>
            \$q->whereJsonContains('events', \$event)
               ->orWhereJsonContains('events', '*')
        );
    }
}
PHP;
    }

    public function generateJob(): string
    {
        return <<<PHP
<?php

namespace App\Jobs;

use App\Models\WebhookSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int \$tries      = 5;
    public int \$maxExceptions = 3;

    public function __construct(
        private readonly WebhookSubscription \$subscription,
        private readonly string              \$event,
        private readonly array               \$payload,
    ) {}

    public function handle(): void
    {
        \$body      = json_encode(\$this->payload);
        \$timestamp = time();
        \$signature = hash_hmac('sha256', \$timestamp . '.' . \$body, \$this->subscription->secret);

        try {
            \$response = Http::withHeaders([
                'Content-Type'         => 'application/json',
                'X-Webhook-Event'      => \$this->event,
                'X-Webhook-Timestamp'  => \$timestamp,
                'X-Webhook-Signature'  => 'sha256=' . \$signature,
            ])
            ->timeout(10)
            ->retry(3, 1000)
            ->post(\$this->subscription->url, \$this->payload);

            if (\$response->successful()) {
                \$this->subscription->update([
                    'failure_count'   => 0,
                    'last_success_at' => now(),
                ]);
            } else {
                \$this->handleFailure("HTTP {$response->status()}");
            }
        } catch (\Throwable \$e) {
            \$this->handleFailure(\$e->getMessage());
            throw \$e;
        }
    }

    public function backoff(): array
    {
        // Exponential back-off: 10s, 60s, 300s, 900s, 3600s
        return [10, 60, 300, 900, 3600];
    }

    private function handleFailure(string \$reason): void
    {
        \$count = \$this->subscription->failure_count + 1;
        \$update = [
            'failure_count'   => \$count,
            'last_failure_at' => now(),
        ];

        // Auto-disable after 10 consecutive failures
        if (\$count >= 10) {
            \$update['is_active'] = false;
        }

        \$this->subscription->update(\$update);

        Log::warning('Webhook delivery failed', [
            'subscription_id' => \$this->subscription->id,
            'url'             => \$this->subscription->url,
            'event'           => \$this->event,
            'reason'          => \$reason,
            'failure_count'   => \$count,
        ]);
    }
}
PHP;
    }

    public function generateDispatcher(string $modelName): string
    {
        $variable = Str::camel($modelName);
        $events   = [
            Str::snake($modelName) . '.created',
            Str::snake($modelName) . '.updated',
            Str::snake($modelName) . '.deleted',
        ];

        $eventsComment = implode(', ', $events);

        return <<<PHP
<?php

namespace App\Webhooks;

use App\Jobs\SendWebhookJob;
use App\Models\\{$modelName};
use App\Models\WebhookSubscription;

/**
 * Dispatches outbound webhooks for {$modelName} lifecycle events.
 * Events: {$eventsComment}
 *
 * Register in {$modelName}Observer::created/updated/deleted.
 */
class {$modelName}Webhook
{
    public static function dispatch(string \$event, {$modelName} \${$variable}): void
    {
        \$payload = [
            'event'     => \$event,
            'timestamp' => now()->toISOString(),
            'data'      => \${$variable}->toArray(),
        ];

        WebhookSubscription::active()
            ->forEvent(\$event)
            ->each(fn(\$sub) => SendWebhookJob::dispatch(\$sub, \$event, \$payload));
    }
}
PHP;
    }

    public function generateMigration(): string
    {
        $table = now()->format('Y_m_d_His') . '_create_webhook_subscriptions_table';

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint \$table) {
            \$table->id();
            \$table->string('url');
            \$table->json('events')->comment('Array of event names, or ["*"] for all');
            \$table->string('secret', 64)->comment('HMAC signing secret');
            \$table->string('description')->nullable();
            \$table->boolean('is_active')->default(true)->index();
            \$table->unsignedSmallInteger('failure_count')->default(0);
            \$table->timestamp('last_success_at')->nullable();
            \$table->timestamp('last_failure_at')->nullable();
            \$table->timestamps();

            \$table->index(['is_active', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
    }
};
PHP;
    }
}

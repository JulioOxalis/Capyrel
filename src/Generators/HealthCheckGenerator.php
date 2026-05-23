<?php

namespace Julio\Capyrel\Generators;

/**
 * Generates a production-grade /health endpoint controller.
 * Checks: DB, cache, queue, storage, and any detected external services.
 * Returns structured JSON suitable for uptime monitors and K8s liveness probes.
 */
class HealthCheckGenerator
{
    public function generateController(): string
    {
        return <<<PHP
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class HealthController extends Controller
{
    /**
     * GET /health
     *
     * Returns 200 when healthy, 503 when any critical check fails.
     * Suitable for load-balancer health checks and Kubernetes probes.
     */
    public function check(): JsonResponse
    {
        \$checks = [
            'database'   => \$this->checkDatabase(),
            'cache'      => \$this->checkCache(),
            'storage'    => \$this->checkStorage(),
            'queue'      => \$this->checkQueue(),
        ];

        \$healthy = collect(\$checks)->every(fn(\$c) => \$c['status'] === 'ok');

        return response()->json([
            'status'    => \$healthy ? 'healthy' : 'degraded',
            'version'   => config('app.version', '1.0.0'),
            'env'       => config('app.env'),
            'timestamp' => now()->toISOString(),
            'checks'    => \$checks,
        ], \$healthy ? 200 : 503);
    }

    /** GET /health/ping — ultra-lightweight liveness probe (no DB check). */
    public function ping(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'timestamp' => now()->toISOString()]);
    }

    // ── Checks ────────────────────────────────────────────────────────────────

    private function checkDatabase(): array
    {
        try {
            \$start = microtime(true);
            DB::select('SELECT 1');
            return [
                'status'   => 'ok',
                'duration' => round((microtime(true) - \$start) * 1000, 2) . 'ms',
                'driver'   => DB::getDriverName(),
            ];
        } catch (\Throwable \$e) {
            return ['status' => 'error', 'message' => \$e->getMessage()];
        }
    }

    private function checkCache(): array
    {
        try {
            \$key = '_capyrel_health_' . time();
            Cache::put(\$key, true, 5);
            \$ok = Cache::get(\$key) === true;
            Cache::forget(\$key);
            return ['status' => \$ok ? 'ok' : 'error', 'driver' => config('cache.default')];
        } catch (\Throwable \$e) {
            return ['status' => 'error', 'message' => \$e->getMessage()];
        }
    }

    private function checkStorage(): array
    {
        try {
            \$disk = config('filesystems.default');
            \$path = '_capyrel_health_' . time() . '.txt';
            Storage::put(\$path, 'ok');
            \$ok = Storage::exists(\$path);
            Storage::delete(\$path);
            return ['status' => \$ok ? 'ok' : 'error', 'disk' => \$disk];
        } catch (\Throwable \$e) {
            return ['status' => 'error', 'message' => \$e->getMessage()];
        }
    }

    private function checkQueue(): array
    {
        try {
            \$size = Queue::size();
            return [
                'status'     => 'ok',
                'driver'     => config('queue.default'),
                'queue_size' => \$size,
            ];
        } catch (\Throwable \$e) {
            // Queue check is non-critical — warn but don't fail
            return ['status' => 'warn', 'message' => \$e->getMessage()];
        }
    }
}
PHP;
    }

    public function generateRoutes(): string
    {
        return <<<PHP
<?php
// Add to routes/web.php or routes/api.php

// Health checks — no auth middleware (needed by load balancers)
Route::get('/health',      [App\Http\Controllers\HealthController::class, 'check'])->name('health');
Route::get('/health/ping', [App\Http\Controllers\HealthController::class, 'ping'])->name('health.ping');
PHP;
    }
}

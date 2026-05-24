<?php

namespace Julio\Capyrel\Writers;

use Illuminate\Support\Str;

class RouteWriter
{
    private bool $oxalisFound = false;

    /**
     * Detect Oxalis, optionally prompt to install, then write routes.
     * Returns the middleware that was used.
     */
    public function write(
        array $models,
        callable $confirm,
        callable $runComposer
    ): string {
        $this->oxalisFound = $this->detectOxalis();
        $middleware        = 'auth';

        if (!$this->oxalisFound) {
            $install = $confirm(
                'No authentication package detected. Install Oxalis (passkey-first auth for Laravel)?'
            );

            if ($install) {
                // Require the package
                $runComposer('composer require julio/oxalis');

                // Re-check so we know the install succeeded before continuing
                $this->oxalisFound = $this->detectOxalis();

                if ($this->oxalisFound) {
                    // Run the Oxalis installer which publishes config, migrations and sets up the guard
                    $runComposer('php artisan oxalis:install');
                    $this->printOxalisNextSteps();
                }

                $middleware = 'auth';
            }
        } else {
            $middleware = 'auth';
        }

        $this->appendRoutes($models, $middleware);

        return $middleware;
    }

    private function printOxalisNextSteps(): void
    {
        echo "\n";
        echo "  \033[32m✔\033[0m Oxalis installed and configured.\n";
        echo "\n";
        echo "  \033[33mOxalis next steps:\033[0m\n";
        echo "  \033[90m  1.\033[0m Run \033[36mphp artisan migrate\033[0m to create the passkey tables.\n";
        echo "  \033[90m  2.\033[0m Add the \033[36mOxalisUser\033[0m trait to your User model.\n";
        echo "  \033[90m  3.\033[0m Visit \033[36m/oxalis/register\033[0m to set up your first passkey.\n";
        echo "  \033[90m  4.\033[0m Protect routes with \033[36mmiddleware('auth')\033[0m — Oxalis hooks into Laravel's standard guard.\n";
        echo "\n";
    }

    /**
     * Write routes without any terminal interaction (for --force mode).
     */
    public function writeSilent(array $models): string
    {
        $middleware = $this->detectOxalis() ? 'auth' : 'auth';
        $this->appendRoutes($models, $middleware);
        return $middleware;
    }

    /**
     * Write routes with context awareness.
     * Standalone models get top-level resource routes.
     * Dependent models get nested routes under their parent.
     */
    private function appendRoutes(array $models, string $middleware): void
    {
        $this->appendRoutesWithContext($models, $middleware, [], []);
    }

    public function appendRoutesWithContext(array $standaloneModels, string $middleware, array $nestedModels, array $nestedParents): void
    {
        $webPath  = base_path('routes/web.php');
        $existing = file_exists($webPath) ? file_get_contents($webPath) : "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n";

        $lines   = [];
        $lines[] = '';
        $lines[] = '// capyrel: generated resource routes';
        $lines[] = "Route::middleware(['{$middleware}'])->group(function () {";

        // Standalone models → top-level routes
        foreach ($standaloneModels as $model) {
            $prefix     = Str::kebab(Str::plural($model));
            $controller = "App\\Http\\Controllers\\{$model}Controller";
            if (!str_contains($existing, "Route::resource('{$prefix}'")) {
                $lines[] = "    Route::resource('{$prefix}', {$controller}::class);";
            }
        }

        // Dependent models → nested routes with ->shallow()
        foreach ($nestedModels as $i => $model) {
            $parent      = $nestedParents[$i] ?? null;
            if (!$parent) continue;

            $parentPrefix = Str::kebab(Str::plural($parent));
            $childPrefix  = Str::kebab(Str::plural($model));
            $controller   = "App\\Http\\Controllers\\{$model}Controller";
            $nestedRoute  = "{$parentPrefix}.{$childPrefix}";

            if (!str_contains($existing, "Route::resource('{$nestedRoute}'")) {
                // shallow() means: index/create/store use parent, show/edit/update/destroy use child only
                $lines[] = "    Route::resource('{$nestedRoute}', {$controller}::class)->shallow()->except(['index', 'show', 'create']);";
            }
        }

        $lines[] = '});';

        $block = implode("\n", $lines);

        if (str_contains($block, 'Route::resource')) {
            file_put_contents($webPath, $existing . "\n" . $block . "\n");
        }
    }

    private function detectOxalis(): bool
    {
        $composerPath = base_path('composer.json');
        if (!file_exists($composerPath)) return false;

        $composer = json_decode(file_get_contents($composerPath), true) ?? [];
        $packages = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);

        return isset($packages['julio/oxalis']);
    }

    public function hasOxalis(): bool
    {
        return $this->oxalisFound;
    }
}

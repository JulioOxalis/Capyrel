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
                $runComposer('composer require julio/oxalis:@dev');
                $this->oxalisFound = true;
                $middleware        = 'auth';
            }
        } else {
            $middleware = 'auth';
        }

        $this->appendRoutes($models, $middleware);

        return $middleware;
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

    private function appendRoutes(array $models, string $middleware): void
    {
        $webPath = base_path('routes/web.php');
        $existing = file_exists($webPath) ? file_get_contents($webPath) : "<?php\n\nuse Illuminate\Support\Facades\Route;\n";

        $lines   = [];
        $lines[] = '';
        $lines[] = '// capyrel: generated resource routes';
        $lines[] = "Route::middleware(['{$middleware}'])->group(function () {";

        foreach ($models as $model) {
            $prefix     = Str::kebab(Str::plural($model));
            $controller = "App\\Http\\Controllers\\{$model}Controller";
            $lines[]    = "    Route::resource('{$prefix}', {$controller}::class);";
        }

        $lines[] = '});';

        // Deduplicate — don't add routes that already exist
        $block = implode("\n", $lines);
        foreach ($models as $model) {
            $prefix = Str::kebab(Str::plural($model));
            if (str_contains($existing, "Route::resource('{$prefix}'")) {
                $block = str_replace("    Route::resource('{$prefix}', App\\Http\\Controllers\\{$model}Controller::class);\n", '', $block);
            }
        }

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

<?php

namespace Julio\Capyrel\Detectors;

class FrameworkDetector
{
    private ?string $cached      = null;
    private ?bool   $alpineCached = null;
    private ?bool   $livewireCached = null;

    public function detect(): string
    {
        if ($this->cached) return $this->cached;
        return $this->cached = $this->resolve();
    }

    public function isTailwind(): bool  { return $this->detect() === 'tailwind'; }
    public function isBootstrap(): bool { return $this->detect() === 'bootstrap'; }

    public function hasAlpine(): bool
    {
        if ($this->alpineCached !== null) return $this->alpineCached;

        $pkgPath = base_path('package.json');
        if (file_exists($pkgPath)) {
            $pkg  = json_decode(file_get_contents($pkgPath), true) ?? [];
            $deps = array_merge($pkg['dependencies'] ?? [], $pkg['devDependencies'] ?? []);
            if (isset($deps['alpinejs']) || isset($deps['@alpinejs/morph'])) {
                return $this->alpineCached = true;
            }
        }

        // Breeze / Jetstream include Alpine by default
        $composerPath = base_path('composer.json');
        if (file_exists($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath), true) ?? [];
            $packages = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);
            if (isset($packages['laravel/breeze']) || isset($packages['laravel/jetstream'])) {
                return $this->alpineCached = true;
            }
        }

        // Check if Alpine is referenced in any blade layout
        $layouts = glob(resource_path('views/layouts/*.blade.php'));
        foreach (array_slice($layouts, 0, 3) as $layout) {
            if (str_contains(file_get_contents($layout), 'alpine') || str_contains(file_get_contents($layout), 'x-data')) {
                return $this->alpineCached = true;
            }
        }

        return $this->alpineCached = false;
    }

    public function hasLivewire(): bool
    {
        if ($this->livewireCached !== null) return $this->livewireCached;

        $composerPath = base_path('composer.json');
        if (!file_exists($composerPath)) return $this->livewireCached = false;

        $composer = json_decode(file_get_contents($composerPath), true) ?? [];
        $packages = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);

        return $this->livewireCached = isset($packages['livewire/livewire']);
    }

    private function resolve(): string
    {
        // 1. Check package.json dependencies
        $pkgPath = base_path('package.json');
        if (file_exists($pkgPath)) {
            $pkg  = json_decode(file_get_contents($pkgPath), true) ?? [];
            $deps = array_merge($pkg['dependencies'] ?? [], $pkg['devDependencies'] ?? []);

            if (isset($deps['tailwindcss']))  return 'tailwind';
            if (isset($deps['bootstrap']))    return 'bootstrap';
        }

        // 2. Check composer.json — Laravel Breeze / Jetstream = Tailwind, Laravel UI = Bootstrap
        $composerPath = base_path('composer.json');
        if (file_exists($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath), true) ?? [];
            $packages = array_merge(
                $composer['require']     ?? [],
                $composer['require-dev'] ?? []
            );

            if (isset($packages['laravel/jetstream']))  return 'tailwind';
            if (isset($packages['laravel/breeze']))     return 'tailwind';
            if (isset($packages['laravel/ui']))         return 'bootstrap';
        }

        // 3. Check app.css for @tailwind directives
        $cssPaths = [
            resource_path('css/app.css'),
            resource_path('sass/app.scss'),
        ];
        foreach ($cssPaths as $css) {
            if (file_exists($css)) {
                $content = file_get_contents($css);
                if (str_contains($content, '@tailwind')) return 'tailwind';
                if (str_contains($content, 'bootstrap'))  return 'bootstrap';
            }
        }

        // 4. Check vite.config.js / webpack.mix.js
        if (file_exists(base_path('vite.config.js'))) {
            $vite = file_get_contents(base_path('vite.config.js'));
            if (str_contains($vite, 'tailwind')) return 'tailwind';
        }

        return 'plain';
    }

    public function layoutExtend(): string
    {
        return match ($this->detect()) {
            'tailwind'  => '', // uses x-app-layout component
            'bootstrap' => "@extends('layouts.app')",
            default     => "@extends('layouts.app')",
        };
    }

    public function wrapOpen(string $title): string
    {
        return match ($this->detect()) {
            'tailwind' => "<x-app-layout>\n    <x-slot name=\"header\">\n        <h2 class=\"font-semibold text-xl text-gray-800 leading-tight\">{$title}</h2>\n    </x-slot>\n    <div class=\"py-12\">\n        <div class=\"max-w-7xl mx-auto sm:px-6 lg:px-8\">\n            <div class=\"bg-white overflow-hidden shadow-sm sm:rounded-lg\">\n                <div class=\"p-6 text-gray-900\">",
            default    => "<div class=\"container py-4\">\n    <div class=\"row\">\n        <div class=\"col-md-12\">",
        };
    }

    public function wrapClose(): string
    {
        return match ($this->detect()) {
            'tailwind' => "                </div>\n            </div>\n        </div>\n    </div>\n</x-app-layout>",
            default    => "        </div>\n    </div>\n</div>",
        };
    }
}

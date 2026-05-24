<?php

namespace Julio\Capyrel\UI;

use Julio\Capyrel\UI\Adapters\BladeAdminAdapter;
use Julio\Capyrel\UI\Adapters\BladeBasicAdapter;
use Julio\Capyrel\UI\Adapters\BladeBlogAdapter;
use Julio\Capyrel\UI\Adapters\BladeMarketplaceAdapter;
use Julio\Capyrel\UI\Adapters\BladeSocialAdapter;
use Julio\Capyrel\UI\Adapters\LivewireModernAdapter;
use Julio\Capyrel\UI\Contracts\UiAdapter;
use RuntimeException;

/**
 * Registry and resolver for UI adapters.
 *
 * Boot sequence:
 *   1. Register built-in adapters
 *   2. Load bootstrap/cache/capyrel-ui.php if present (production pre-warm)
 *   3. Run AdapterDiscovery to register third-party adapters from capyrel.json
 *
 * Resolution order:
 *   1. Model-specific binding  (registry->use('Post', 'blade-social'))
 *   2. Global default adapter  (config capyrel.ui.adapter)
 *   3. Built-in fallback       (blade-basic)
 */
class UiAdapterRegistry
{
    /** @var array<string, callable> name → factory */
    private array $factories = [];

    /** @var array<string, UiAdapter> name → cached instance */
    private array $instances = [];

    /** @var array<string, string> ModelName → adapter name */
    private array $modelBindings = [];

    private string $defaultAdapter = 'blade-basic';

    private bool $cacheLoaded = false;

    public function __construct()
    {
        $this->registerBuiltIns();
        $this->loadCache();
        $this->runDiscovery();
    }

    // ── Registration ──────────────────────────────────────────────────────────

    /**
     * Register a named adapter via a factory callable.
     */
    public function register(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;
        unset($this->instances[$name]); // bust instance cache on re-registration
    }

    /**
     * Bind a specific adapter to a model.
     *
     * Capyrel::ui()->use('Post', 'blade-social');
     */
    public function use(string $model, string $adapterName): void
    {
        $this->modelBindings[$model] = $adapterName;
    }

    /**
     * Set the global default adapter name.
     */
    public function setDefault(string $adapterName): void
    {
        $this->defaultAdapter = $adapterName;
    }

    // ── Resolution ────────────────────────────────────────────────────────────

    /**
     * Resolve the correct adapter for the given model (or the global default).
     */
    public function resolve(?string $model = null): UiAdapter
    {
        $name = $this->resolveName($model);

        if (! isset($this->instances[$name])) {
            $this->instances[$name] = $this->make($name);
        }

        return $this->instances[$name];
    }

    // ── Introspection ─────────────────────────────────────────────────────────

    /**
     * Return all instantiated adapters (used by UiCacheCommand).
     *
     * @return array<string, UiAdapter>
     */
    public function all(): array
    {
        foreach (array_keys($this->factories) as $name) {
            if (! isset($this->instances[$name])) {
                try {
                    $this->instances[$name] = $this->make($name);
                } catch (\Throwable) {
                    // skip un-instantiatable adapters
                }
            }
        }

        return $this->instances;
    }

    /**
     * Return the current default adapter name.
     */
    public function defaultName(): string
    {
        return $this->defaultAdapter;
    }

    /**
     * Return a summary of all registered adapters (for capyrel:ui:list etc.).
     */
    public function registry(): array
    {
        $out = [];

        foreach ($this->factories as $name => $_) {
            $out[$name] = [
                'name'         => $name,
                'capabilities' => $this->capabilitiesFor($name),
            ];
        }

        return $out;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveName(?string $model): string
    {
        if ($model && isset($this->modelBindings[$model])) {
            return $this->modelBindings[$model];
        }

        return $this->defaultAdapter;
    }

    private function make(string $name): UiAdapter
    {
        if (isset($this->factories[$name])) {
            $adapter = ($this->factories[$name])();

            if (! $adapter instanceof UiAdapter) {
                throw new RuntimeException("Adapter factory for [{$name}] must return a UiAdapter instance.");
            }

            return $adapter;
        }

        // Graceful fallback to blade-basic rather than hard failure
        if ($name !== 'blade-basic') {
            return $this->make('blade-basic');
        }

        throw new RuntimeException("No adapter registered for [{$name}] and blade-basic fallback is missing.");
    }

    private function capabilitiesFor(string $name): array
    {
        try {
            return $this->make($name)->capabilities();
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Boot sequence ─────────────────────────────────────────────────────────

    private function registerBuiltIns(): void
    {
        $this->register('blade-basic',        fn() => new BladeBasicAdapter());
        $this->register('blade-social',       fn() => new BladeSocialAdapter());
        $this->register('blade-admin',        fn() => new BladeAdminAdapter());
        $this->register('blade-marketplace',  fn() => new BladeMarketplaceAdapter());
        $this->register('blade-blog',         fn() => new BladeBlogAdapter());
        $this->register('livewire-modern',    fn() => new LivewireModernAdapter());
    }

    /**
     * Load bootstrap/cache/capyrel-ui.php if it exists.
     * The cache file records model-adapter bindings and the default adapter
     * set at the time `capyrel:ui:cache` was run. The factory closures
     * for built-ins are already registered above — the cache supplements
     * with third-party class names.
     */
    private function loadCache(): void
    {
        $cachePath = base_path('bootstrap/cache/capyrel-ui.php');

        if (! file_exists($cachePath)) {
            return;
        }

        try {
            $cache = require $cachePath;
        } catch (\Throwable) {
            return;
        }

        if (! is_array($cache)) {
            return;
        }

        // Restore default
        if (! empty($cache['default'])) {
            $this->defaultAdapter = (string) $cache['default'];
        }

        // Restore model bindings
        foreach ((array) ($cache['model_adapters'] ?? []) as $model => $adapterName) {
            $this->modelBindings[$model] = $adapterName;
        }

        // Re-register any third-party adapters that are in the cache
        // (built-ins are already registered via registerBuiltIns())
        foreach ((array) ($cache['adapters'] ?? []) as $name => $meta) {
            if (isset($this->factories[$name])) {
                continue; // built-in already registered
            }

            $class = $meta['class'] ?? null;

            if ($class && class_exists($class) && is_a($class, UiAdapter::class, true)) {
                $this->register($name, fn() => new $class());
            }
        }

        $this->cacheLoaded = true;
    }

    /**
     * Scan vendor packages for capyrel.json manifests and register
     * any declared adapters that are not already known.
     */
    private function runDiscovery(): void
    {
        try {
            $discovered = (new AdapterDiscovery())->discover();
        } catch (\Throwable) {
            return;
        }

        foreach ($discovered as $name => $adapter) {
            if (isset($this->factories[$name])) {
                continue; // don't override built-ins or cache entries
            }

            $class = get_class($adapter);
            $this->register($name, fn() => new $class());
        }
    }
}

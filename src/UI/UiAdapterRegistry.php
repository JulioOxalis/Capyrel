<?php

namespace Julio\Capyrel\UI;

use Julio\Capyrel\UI\Adapters\BladeAdminAdapter;
use Julio\Capyrel\UI\Adapters\BladeBasicAdapter;
use Julio\Capyrel\UI\Adapters\BladeMarketplaceAdapter;
use Julio\Capyrel\UI\Adapters\BladeSocialAdapter;
use Julio\Capyrel\UI\Contracts\UiAdapter;
use RuntimeException;

/**
 * Registry and resolver for UI adapters.
 *
 * Resolution order:
 *   1. Model-specific binding  (Capyrel::ui()->use('Post', 'blade-social'))
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

    public function __construct()
    {
        $this->registerBuiltIns();
    }

    // ── Registration ──────────────────────────────────────────────────────────

    /**
     * Register a named adapter via a factory callable.
     */
    public function register(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;
        unset($this->instances[$name]); // bust cache on re-registration
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

        if (!isset($this->instances[$name])) {
            $this->instances[$name] = $this->make($name);
        }

        return $this->instances[$name];
    }

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

            if (!$adapter instanceof UiAdapter) {
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

    // ── Introspection ─────────────────────────────────────────────────────────

    /**
     * Return a summary of all registered adapters.
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

    private function capabilitiesFor(string $name): array
    {
        try {
            return $this->make($name)->capabilities();
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Built-ins ─────────────────────────────────────────────────────────────

    private function registerBuiltIns(): void
    {
        $this->register('blade-basic',        fn() => new BladeBasicAdapter());
        $this->register('blade-social',       fn() => new BladeSocialAdapter());
        $this->register('blade-admin',        fn() => new BladeAdminAdapter());
        $this->register('blade-marketplace',  fn() => new BladeMarketplaceAdapter());
    }
}

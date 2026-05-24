<?php

namespace Julio\Capyrel\Wizard;

/**
 * Probes the host Laravel project to surface context the wizard needs:
 * installed packages, existing models, existing migrations, PHP/Laravel versions.
 */
class LaravelAwareness
{
    private ?array $composerJson = null;

    // ── Installed package detection ───────────────────────────────────────────

    public function hasPackage(string $package): bool
    {
        $data = $this->composer();
        $all  = array_merge(
            $data['require']         ?? [],
            $data['require-dev']     ?? [],
        );

        return isset($all[$package]);
    }

    public function hasLivewire(): bool   { return $this->hasPackage('livewire/livewire'); }
    public function hasSanctum(): bool    { return $this->hasPackage('laravel/sanctum'); }
    public function hasPassport(): bool   { return $this->hasPackage('laravel/passport'); }
    public function hasSpatieMedia(): bool { return $this->hasPackage('spatie/laravel-medialibrary'); }
    public function hasSpatieRoles(): bool { return $this->hasPackage('spatie/laravel-permission'); }
    public function hasScout(): bool      { return $this->hasPackage('laravel/scout'); }
    public function hasCashier(): bool    { return $this->hasPackage('laravel/cashier'); }
    public function hasTelescope(): bool  { return $this->hasPackage('laravel/telescope'); }
    public function hasFilament(): bool   { return $this->hasPackage('filament/filament'); }

    // ── Existing model detection ──────────────────────────────────────────────

    /**
     * Return names of models already in app/Models/ (without .php extension).
     *
     * @return string[]
     */
    public function existingModels(): array
    {
        $path   = app_path('Models');
        $models = [];

        if (!is_dir($path)) {
            return $models;
        }

        foreach (scandir($path) as $file) {
            if (str_ends_with($file, '.php')) {
                $models[] = basename($file, '.php');
            }
        }

        sort($models);
        return $models;
    }

    // ── Existing migration detection ──────────────────────────────────────────

    /**
     * Return table names that already have a create migration.
     *
     * @return string[]
     */
    public function migratedTables(): array
    {
        $path   = database_path('migrations');
        $tables = [];

        if (!is_dir($path)) {
            return $tables;
        }

        foreach (scandir($path) as $file) {
            if (preg_match('/create_(\w+)_table\.php$/', $file, $m)) {
                $tables[] = $m[1];
            }
        }

        return $tables;
    }

    // ── Version info ──────────────────────────────────────────────────────────

    public function laravelVersion(): string
    {
        return app()->version();
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    // ── Project name ─────────────────────────────────────────────────────────

    public function projectName(): string
    {
        return $this->composer()['name'] ?? basename(base_path());
    }

    // ── Summary for wizard display ────────────────────────────────────────────

    /**
     * Return a one-line context string shown at the top of capyrel:new.
     */
    public function contextLine(): string
    {
        $parts = [
            'Laravel ' . $this->laravelVersion(),
            'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        ];

        if ($this->hasLivewire())    $parts[] = 'Livewire';
        if ($this->hasSanctum())     $parts[] = 'Sanctum';
        if ($this->hasPassport())    $parts[] = 'Passport';
        if ($this->hasSpatieRoles()) $parts[] = 'Spatie Roles';
        if ($this->hasSpatieMedia()) $parts[] = 'Spatie Media';
        if ($this->hasScout())       $parts[] = 'Scout';
        if ($this->hasCashier())     $parts[] = 'Cashier';
        if ($this->hasFilament())    $parts[] = 'Filament';

        return implode(' · ', $parts);
    }

    /**
     * Return suggestions based on detected packages.
     * e.g. if Spatie roles is installed, suggest adding a 'role_id' FK.
     *
     * @return string[]
     */
    public function suggestions(): array
    {
        $hints = [];

        if ($this->hasSpatieRoles()) {
            $hints[] = 'Spatie Roles detected — consider adding a role relation to User.';
        }
        if ($this->hasSpatieMedia()) {
            $hints[] = 'Spatie Media Library detected — image/file fields will auto-wire media collections.';
        }
        if ($this->hasScout()) {
            $hints[] = 'Laravel Scout detected — string-heavy models will get a Searchable trait.';
        }
        if ($this->hasCashier()) {
            $hints[] = 'Laravel Cashier detected — consider a Plan or Subscription model.';
        }

        return $hints;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function composer(): array
    {
        if ($this->composerJson !== null) {
            return $this->composerJson;
        }

        $path = base_path('composer.json');

        if (!file_exists($path)) {
            return $this->composerJson = [];
        }

        $decoded = json_decode(file_get_contents($path), true);
        return $this->composerJson = is_array($decoded) ? $decoded : [];
    }
}

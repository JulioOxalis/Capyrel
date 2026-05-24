<?php

namespace Julio\Capyrel\UI\Contracts;

/**
 * Optional interface for adapters that need to emit additional files
 * beyond the standard Blade views (e.g. Livewire PHP component classes).
 *
 * Implement this alongside UiAdapter when your adapter generates PHP files,
 * JS modules, or anything else that lives outside resources/views/.
 */
interface HasExtraFiles
{
    /**
     * Return extra files to write for the given screen.
     *
     * Keys are absolute file paths; values are file contents.
     *
     * Example:
     *   [
     *     app_path('Livewire/PostTable.php')                        => '<?php ...',
     *     resource_path('views/livewire/post-table.blade.php')      => '<div>...</div>',
     *   ]
     *
     * @param  string  $screen   One of: list | create | edit | show
     * @param  array   $contract The UI contract for the model
     * @return array<string, string>  absolute-path => file-content
     */
    public function extraFiles(string $screen, array $contract): array;
}

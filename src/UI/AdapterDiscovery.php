<?php

namespace Julio\Capyrel\UI;

use Illuminate\Support\Facades\File;
use Julio\Capyrel\UI\Contracts\UiAdapter;

/**
 * Scans Composer vendor packages for capyrel.json manifests and returns
 * instantiated adapters ready for registration.
 *
 * capyrel.json format (place in the package root alongside composer.json):
 *
 *   {
 *     "name":         "my-adapter",
 *     "version":      "1.0.0",
 *     "entry":        "Vendor\\Package\\MyAdapter",
 *     "capabilities": ["list", "create", "edit", "show"]
 *   }
 *
 * The "entry" class must implement Julio\Capyrel\UI\Contracts\UiAdapter.
 */
class AdapterDiscovery
{
    private string $vendorPath;
    private string $manifestName;

    public function __construct(string $vendorPath = '', string $manifestName = 'capyrel.json')
    {
        $this->vendorPath   = $vendorPath ?: base_path('vendor');
        $this->manifestName = $manifestName;
    }

    /**
     * Discover all third-party adapters from capyrel.json manifests.
     *
     * @return array<string, UiAdapter>  adapter-name => instance
     */
    public function discover(): array
    {
        $adapters = [];

        foreach ($this->findManifests() as $manifestPath) {
            $manifest = $this->parseManifest($manifestPath);

            if ($manifest === null) {
                continue;
            }

            $adapter = $this->instantiate($manifest, $manifestPath);

            if ($adapter !== null) {
                $adapters[$adapter->name()] = $adapter;
            }
        }

        return $adapters;
    }

    /**
     * Return all capyrel.json paths under vendor/.
     *
     * @return string[]
     */
    public function findManifests(): array
    {
        if (! is_dir($this->vendorPath)) {
            return [];
        }

        $manifests = [];

        // vendor/<vendor>/<package>/capyrel.json
        foreach (File::directories($this->vendorPath) as $vendorDir) {
            foreach (File::directories($vendorDir) as $packageDir) {
                $candidate = $packageDir . DIRECTORY_SEPARATOR . $this->manifestName;
                if (file_exists($candidate)) {
                    $manifests[] = $candidate;
                }
            }
        }

        return $manifests;
    }

    /**
     * Parse and validate a capyrel.json file.
     *
     * Returns null when the file is invalid or missing required keys.
     *
     * @return array{name: string, version: string, entry: string, capabilities: string[]}|null
     */
    private function parseManifest(string $path): ?array
    {
        $raw = @file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return null;
        }

        if (empty($data['name']) || empty($data['entry'])) {
            return null;
        }

        return [
            'name'         => (string) $data['name'],
            'version'      => (string) ($data['version'] ?? 'unknown'),
            'entry'        => (string) $data['entry'],
            'capabilities' => (array)  ($data['capabilities'] ?? []),
        ];
    }

    /**
     * Attempt to instantiate the adapter class declared in the manifest.
     *
     * Returns null if the class doesn't exist or doesn't implement UiAdapter.
     */
    private function instantiate(array $manifest, string $manifestPath): ?UiAdapter
    {
        $class = $manifest['entry'];

        if (! class_exists($class)) {
            report(new \RuntimeException(
                "Capyrel: adapter class '{$class}' declared in {$manifestPath} does not exist."
            ));

            return null;
        }

        if (! is_a($class, UiAdapter::class, true)) {
            report(new \RuntimeException(
                "Capyrel: '{$class}' must implement " . UiAdapter::class
            ));

            return null;
        }

        return new $class();
    }
}

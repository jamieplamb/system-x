<?php

namespace SystemX\Core\Support;

use InvalidArgumentException;

// The client-asset extension seam, symmetric to Wire\WidgetRegistry and Runtime\AppRegistry.
// A vendor package registers its client bundle (a dist dir + optional js/css filenames) here,
// in its provider's boot(), keyed by its VENDOR ID (the first dotted segment shared by its
// widget types, e.g. `example` for types `example.gauge`/`example.todo`). One package ships one
// bundle registering all its renderers, so the natural key is the vendor prefix -- doctor pairs a
// dotted widget type to a bundle by str_starts_with($type, $namespace.'.'). Emission (Assets
// vendor tag builders) and serving (AssetController::serveVendor) both read this registry.
class AssetRegistry
{
    /** @var array<string, array{dir: string, js: ?string, css: ?string}> */
    private array $bundles = [];

    public function register(string $namespace, string $distDir, ?string $js = null, ?string $css = null): void
    {
        if (isset($this->bundles[$namespace])) {
            throw new InvalidArgumentException("system-x: vendor asset namespace \"{$namespace}\" is already registered -- two packages claiming the same namespace collide. Use your vendor id (the first dotted segment of your widget types).");
        }

        $this->bundles[$namespace] = ['dir' => $distDir, 'js' => $js, 'css' => $css];
    }

    public function has(string $namespace): bool
    {
        return isset($this->bundles[$namespace]);
    }

    /** @return array{dir: string, js: ?string, css: ?string}|null */
    public function get(string $namespace): ?array
    {
        return $this->bundles[$namespace] ?? null;
    }

    /** @return array<string, array{dir: string, js: ?string, css: ?string}> */
    public function all(): array
    {
        return $this->bundles;
    }

    /** @return array<int, string> */
    public function namespaces(): array
    {
        return array_keys($this->bundles);
    }
}

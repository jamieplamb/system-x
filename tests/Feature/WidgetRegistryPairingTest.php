<?php

namespace Tests\Feature;

use SystemX\Core\Wire\WidgetRegistry;
use Tests\TestCase;

class WidgetRegistryPairingTest extends TestCase
{
    public function test_every_php_registered_type_has_a_js_renderer(): void
    {
        $registry = $this->app->make(WidgetRegistry::class);
        $jsManifest = $this->jsManifest();

        foreach ($registry->types() as $type) {
            // Vendor-namespaced (dotted) types ship their renderer in a vendor bundle, not core's
            // manifest -- they pair via the AssetRegistry (see DoctorCommandTest). Exempt them here.
            if (str_contains($type, '.')) {
                continue;
            }

            $this->assertContains(
                $type,
                $jsManifest,
                "PHP type \"{$type}\" has no JS renderer in registered-types.json -- the PHP/JS halves drifted.",
            );
        }
    }

    public function test_every_js_renderer_has_a_php_builder(): void
    {
        $registry = $this->app->make(WidgetRegistry::class);
        $jsManifest = $this->jsManifest();

        foreach ($jsManifest as $type) {
            $this->assertContains(
                $type,
                $registry->types(),
                "JS renderer \"{$type}\" has no PHP builder in WidgetRegistry -- the PHP/JS halves drifted.",
            );
        }
    }

    /** @return array<int, string> */
    private function jsManifest(): array
    {
        // The JS half now lives in the package -- the host frontend was deleted (the desktop
        // ships entirely from the package). The manifest there is the live source of truth that
        // the package Vitest pins against the real renderers; this test closes the PHP side.
        $manifest = json_decode(
            file_get_contents(base_path('packages/system-x/core/resources/js/system-x/registered-types.json')),
            true,
        );

        $this->assertIsArray($manifest, 'registered-types.json missing or invalid JSON');

        return $manifest;
    }
}

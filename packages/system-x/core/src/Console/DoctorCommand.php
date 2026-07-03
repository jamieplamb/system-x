<?php

namespace SystemX\Core\Console;

use Illuminate\Console\Command;
use SystemX\Core\Runtime\AppRegistry;
use SystemX\Core\Support\AssetRegistry;
use SystemX\Core\Wire\WidgetRegistry;

// A consumer-facing setup check: "did my package load + is my setup sane?". Lists registered apps
// (a third-party author confirms THEIR provider was discovered) and cross-checks widget types
// against their client half. BARE (core) types pair against the JS manifest; DOTTED (vendor) types
// pair against the AssetRegistry (their JS ships separately, so the manifest can't see them). Exit
// non-zero on a mismatch so it's CI-usable. See the shipping-a-system-x-app skill.
class DoctorCommand extends Command
{
    protected $signature = 'system-x:doctor';

    protected $description = 'Diagnose a system-x setup: registered apps, widget PHP/JS pairing.';

    public function handle(AppRegistry $apps, WidgetRegistry $widgets, AssetRegistry $assets): int
    {
        $ok = true;

        $meta = $apps->metadata();
        $this->info(count($meta).' app(s) registered:');
        foreach ($meta as $app) {
            $flag = $app['system'] ? ' [system]' : '';
            $this->line("  - {$app['slug']} ({$app['title']}){$flag}");
        }

        $phpTypes = $widgets->types();

        // Bare (core) types pair against the JS manifest. Dotted (vendor) types pair against the
        // AssetRegistry -- their renderer ships in a vendor bundle, never in core's manifest.
        $bareTypes = array_values(array_filter($phpTypes, fn (string $t): bool => ! str_contains($t, '.')));
        $vendorTypes = array_values(array_filter($phpTypes, fn (string $t): bool => str_contains($t, '.')));

        // Manifest resolved PACKAGE-RELATIVE (this command ships in vendor/system-x/core in a real
        // consumer app -- NEVER base_path()). Precedent: Support/Assets.
        $manifestPath = dirname(__DIR__, 2).'/resources/js/system-x/registered-types.json';
        $jsTypes = is_file($manifestPath) ? (json_decode((string) file_get_contents($manifestPath), true) ?: []) : [];

        $missingJs = array_diff($bareTypes, $jsTypes);
        $missingPhp = array_diff($jsTypes, $bareTypes);

        // Vendor types with no matching registered bundle (namespace is a dotted prefix of the type).
        $namespaces = $assets->namespaces();
        $vendorMissing = array_values(array_filter(
            $vendorTypes,
            fn (string $t): bool => ! $this->hasBundle($t, $namespaces),
        ));

        if ($missingJs === [] && $missingPhp === [] && $vendorMissing === []) {
            $this->info(count($phpTypes).' widget type(s) registered -- PHP/JS pairing OK.');
        } else {
            $ok = false;
            foreach ($missingJs as $t) {
                $this->error("widget type \"{$t}\" has a PHP builder but NO JS renderer -- it renders as the unknown placeholder.");
            }
            foreach ($missingPhp as $t) {
                $this->error("widget type \"{$t}\" has a JS renderer but NO PHP builder.");
            }
            foreach ($vendorMissing as $t) {
                $this->error("widget type \"{$t}\" has a PHP builder but no registered client asset bundle -- ship its JS via the AssetRegistry (a vendor bundle whose namespace prefixes the type).");
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<int, string> $namespaces */
    private function hasBundle(string $type, array $namespaces): bool
    {
        foreach ($namespaces as $namespace) {
            if (str_starts_with($type, $namespace.'.')) {
                return true;
            }
        }

        return false;
    }
}

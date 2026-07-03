<?php

namespace Tests\Feature;

use SystemX\Core\Support\Assets;
use Tests\TestCase;

class AssetDirectivesTest extends TestCase
{
    public function test_directives_emit_hashed_asset_tags(): void
    {
        $html = \Blade::render('@systemxStyles @systemxScripts');

        $this->assertStringContainsString('/system-x/assets/system-x.', $html);
        $this->assertStringContainsString('.css', $html);
        $this->assertStringContainsString('<script src="/system-x/assets/system-x.', $html);
    }

    public function test_the_script_tag_is_deferred(): void
    {
        // load-bearing: the IIFE boots immediately + needs the body mounted first
        $html = \Blade::render('@systemxScripts');
        $this->assertStringContainsString('defer', $html);
    }

    public function test_the_emitted_urls_carry_the_current_content_hash(): void
    {
        // the directive URL must match what AssetController serves, or assets 404
        $cssHash = app(Assets::class)->hash('system-x.css');
        $jsHash = app(Assets::class)->hash('system-x.js');
        $html = \Blade::render('@systemxStyles @systemxScripts');

        $this->assertStringContainsString("system-x.{$cssHash}.css", $html);
        $this->assertStringContainsString("system-x.{$jsHash}.js", $html);
    }

    public function test_greeter_directive_emits_the_greeter_bundle_deferred(): void
    {
        $hash = app(Assets::class)->hash('greeter.js');
        $html = \Blade::render('@systemxGreeterScripts');

        $this->assertStringContainsString("/system-x/assets/greeter.{$hash}.js", $html);
        $this->assertStringContainsString('defer', $html);
        // must NOT be the desktop bundle
        $this->assertStringNotContainsString('/assets/system-x.', $html);
    }

    public function test_desktop_scripts_directive_still_emits_the_desktop_bundle(): void
    {
        $hash = app(Assets::class)->hash('system-x.js');
        $html = \Blade::render('@systemxScripts');

        $this->assertStringContainsString("/system-x/assets/system-x.{$hash}.js", $html);
    }
}

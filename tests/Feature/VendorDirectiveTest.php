<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use SystemX\Core\Support\AssetRegistry;
use Tests\TestCase;

class VendorDirectiveTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/sx-vendor-dir-'.uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir.'/example-todo.js', 'x');
        $this->app->make(AssetRegistry::class)->register('fixture', $this->dir, js: 'example-todo.js');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/example-todo.js');
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_vendor_scripts_directive_emits_a_deferred_hashed_tag(): void
    {
        $html = Blade::render('@systemxVendorScripts');

        $this->assertStringContainsString('/system-x/vendor/fixture/example-todo.', $html);
        $this->assertStringContainsString('defer', $html);
    }

    public function test_core_scripts_precede_vendor_scripts_when_rendered_in_head_order(): void
    {
        $html = Blade::render('@systemxScripts'."\n".'@systemxVendorScripts');

        $corePos = strpos($html, '/system-x/assets/system-x.');
        $vendorPos = strpos($html, '/system-x/vendor/fixture/');

        $this->assertNotFalse($corePos);
        $this->assertNotFalse($vendorPos);
        $this->assertLessThan($vendorPos, $corePos, 'core bundle must be emitted before vendor scripts');
    }

    public function test_desktop_view_places_directives_in_the_correct_order(): void
    {
        $blade = file_get_contents(base_path('packages/system-x/core/resources/views/desktop.blade.php'));

        $this->assertLessThan(strpos($blade, '@systemxVendorStyles'), strpos($blade, '@systemxStyles'));
        $this->assertLessThan(strpos($blade, '@systemxVendorScripts'), strpos($blade, '@systemxScripts'));
    }
}

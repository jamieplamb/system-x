<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use SystemX\Core\Support\AssetRegistry;
use SystemX\Core\Wire\WidgetRegistry;
use Tests\TestCase;

class VendorWidgetWiringTest extends TestCase
{
    public function test_the_provider_registers_the_gauge_widget_and_asset_bundle(): void
    {
        $this->assertTrue($this->app->make(WidgetRegistry::class)->has('example.gauge'));

        $bundle = $this->app->make(AssetRegistry::class)->get('example');
        $this->assertNotNull($bundle);
        $this->assertSame('example-todo.js', $bundle['js']);
        $this->assertSame('example-todo.css', $bundle['css']);
    }

    public function test_the_registered_dist_files_exist_on_disk(): void
    {
        $bundle = $this->app->make(AssetRegistry::class)->get('example');
        $this->assertFileExists($bundle['dir'].'/'.$bundle['js']);
        $this->assertFileExists($bundle['dir'].'/'.$bundle['css']);
    }

    public function test_doctor_stays_green_with_the_vendor_widget_live(): void
    {
        $this->assertSame(0, Artisan::call('system-x:doctor'));
    }
}

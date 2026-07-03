<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use SystemX\Core\Support\AssetRegistry;
use SystemX\Core\Widgets\Button;
use SystemX\Core\Wire\WidgetRegistry;
use Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    public function test_a_healthy_setup_exits_zero_and_reports_apps_and_widgets(): void
    {
        $exitCode = Artisan::call('system-x:doctor');

        $this->assertSame(0, $exitCode);

        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/\d+ app\(s\) registered/', $output);
        $this->assertMatchesRegularExpression('/\d+ widget type\(s\) registered/', $output);
    }

    public function test_a_bare_widget_type_missing_its_js_renderer_exits_non_zero(): void
    {
        $this->app->make(WidgetRegistry::class)->register('zonk', Button::class);

        $exitCode = Artisan::call('system-x:doctor');

        $this->assertNotSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('zonk', $output);
        $this->assertStringContainsString('NO JS renderer', $output);
    }

    public function test_a_vendor_type_with_no_registered_bundle_exits_non_zero(): void
    {
        $this->app->make(WidgetRegistry::class)->register('acme.widget', Button::class);

        $exitCode = Artisan::call('system-x:doctor');

        $this->assertNotSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('acme.widget', $output);
        $this->assertStringContainsString('client asset bundle', $output);
    }

    public function test_a_vendor_type_with_a_registered_bundle_passes(): void
    {
        $this->app->make(WidgetRegistry::class)->register('acme.widget', Button::class);
        $this->app->make(AssetRegistry::class)->register('acme', '/abs/dist', js: 'acme.js');

        $exitCode = Artisan::call('system-x:doctor');

        $this->assertSame(0, $exitCode);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MakeAppCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        $path = base_path('app/SystemX/WidgetApp.php');

        if (file_exists($path)) {
            unlink($path);
        }

        $dir = base_path('app/SystemX');

        if (is_dir($dir) && count(scandir($dir)) === 2) {
            rmdir($dir);
        }

        parent::tearDown();
    }

    public function test_it_scaffolds_an_app_class_into_the_host_app(): void
    {
        $exitCode = Artisan::call('system-x:make-app', ['name' => 'Widget']);

        $this->assertSame(0, $exitCode);

        $path = base_path('app/SystemX/WidgetApp.php');
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertStringContainsString('class WidgetApp extends App', $contents);
        $this->assertStringContainsString('function slug()', $contents);
        $this->assertStringContainsString('function render()', $contents);

        $output = Artisan::output();
        $this->assertStringContainsString('AppRegistry', $output);
    }
}

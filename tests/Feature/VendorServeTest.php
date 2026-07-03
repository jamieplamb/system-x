<?php

namespace Tests\Feature;

use SystemX\Core\Support\AssetRegistry;
use SystemX\Core\Support\Assets;
use Tests\TestCase;

class VendorServeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/sx-vendor-serve-'.uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir.'/example-todo.js', 'console.log("gauge")');

        $this->app->make(AssetRegistry::class)->register('fixture', $this->dir, js: 'example-todo.js');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/example-todo.js');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function hash(): string
    {
        return $this->app->make(Assets::class)->hashIn($this->dir, 'example-todo.js');
    }

    public function test_it_serves_a_valid_vendor_asset_with_cache_and_mime(): void
    {
        $response = $this->get("/system-x/vendor/fixture/example-todo.{$this->hash()}.js")->assertOk();

        $this->assertStringStartsWith('text/javascript', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
    }

    public function test_a_stale_hash_404s(): void
    {
        $this->get('/system-x/vendor/fixture/example-todo.deadbeef.js')->assertNotFound();
    }

    public function test_an_unknown_namespace_404s(): void
    {
        $this->get("/system-x/vendor/nope/example-todo.{$this->hash()}.js")->assertNotFound();
    }

    public function test_a_file_not_in_the_registered_dir_404s(): void
    {
        $this->get('/system-x/vendor/fixture/ghost.deadbeef.js')->assertNotFound();
    }

    public function test_a_path_traversal_404s(): void
    {
        $this->get('/system-x/vendor/fixture/..%2f..%2fcomposer.json')->assertNotFound();
    }
}

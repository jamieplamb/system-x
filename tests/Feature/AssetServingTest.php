<?php

namespace Tests\Feature;

use SystemX\Core\Support\Assets;
use Tests\TestCase;

class AssetServingTest extends TestCase
{
    public function test_it_serves_the_js_bundle_with_long_cache_and_correct_mime(): void
    {
        $hash = app(Assets::class)->hash('system-x.js');
        $response = $this->get("/system-x/assets/system-x.{$hash}.js")->assertOk();
        // Symfony's prepare() may append '; charset=utf-8' to text/* types -- assert the
        // correct MIME prefix and all three immutable-cache directives are present.
        $this->assertStringStartsWith('text/javascript', $response->headers->get('Content-Type'));
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=31536000', $cacheControl);
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('immutable', $cacheControl);
    }

    public function test_it_serves_the_css_bundle(): void
    {
        $hash = app(Assets::class)->hash('system-x.css');
        $response = $this->get("/system-x/assets/system-x.{$hash}.css")->assertOk();
        $this->assertStringStartsWith('text/css', $response->headers->get('Content-Type'));
    }

    public function test_it_serves_a_hashed_font_straight_from_dist(): void
    {
        // pick a real woff2 filename from dist/ (glob it in the test) and assert it serves with font/woff2
        $font = basename((string) collect(glob(base_path('packages/system-x/core/dist/*.woff2')))->first());
        $this->get("/system-x/assets/{$font}")->assertOk()->assertHeader('Content-Type', 'font/woff2');
    }

    public function test_a_wrong_hash_404s(): void
    {
        $this->get('/system-x/assets/system-x.deadbeef.js')->assertNotFound();
    }

    public function test_a_path_traversal_404s(): void
    {
        $this->get('/system-x/assets/..%2f..%2fcomposer.json')->assertNotFound();
    }
}

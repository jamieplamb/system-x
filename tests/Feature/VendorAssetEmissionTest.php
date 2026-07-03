<?php

namespace Tests\Feature;

use SystemX\Core\Support\AssetRegistry;
use SystemX\Core\Support\Assets;
use Tests\TestCase;

class VendorAssetEmissionTest extends TestCase
{
    private string $dirA;

    private string $dirB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dirA = sys_get_temp_dir().'/sx-vendor-a-'.uniqid();
        $this->dirB = sys_get_temp_dir().'/sx-vendor-b-'.uniqid();
        mkdir($this->dirA);
        mkdir($this->dirB);
        file_put_contents($this->dirA.'/style.css', 'a{}');
        file_put_contents($this->dirB.'/style.css', 'b{color:red}');
    }

    protected function tearDown(): void
    {
        @unlink($this->dirA.'/style.css');
        @unlink($this->dirB.'/style.css');
        @rmdir($this->dirA);
        @rmdir($this->dirB);
        parent::tearDown();
    }

    public function test_same_named_files_in_different_dirs_hash_independently(): void
    {
        $assets = new Assets;
        $hashA = $assets->hashIn($this->dirA, 'style.css');
        $hashB = $assets->hashIn($this->dirB, 'style.css');

        $this->assertNotSame($hashA, $hashB, 'dir-qualified memo key must not collide on filename');
        $this->assertSame($hashA, $assets->hashIn($this->dirA, 'style.css'));
    }

    public function test_vendor_script_tag_is_deferred_and_hashed(): void
    {
        file_put_contents($this->dirA.'/example-todo.js', 'console.log(1)');

        $registry = new AssetRegistry;
        $registry->register('example', $this->dirA, js: 'example-todo.js');

        $html = (new Assets)->vendorScriptTags($registry);
        $hash = (new Assets)->hashIn($this->dirA, 'example-todo.js');

        $this->assertStringContainsString('src="/system-x/vendor/example/example-todo.'.$hash.'.js"', $html);
        $this->assertStringContainsString('defer', $html);

        @unlink($this->dirA.'/example-todo.js');
    }

    public function test_vendor_style_tag_is_hashed_and_a_null_half_emits_nothing(): void
    {
        $registry = new AssetRegistry;
        $registry->register('example', $this->dirA, js: null, css: 'style.css');

        $assets = new Assets;
        $styles = $assets->vendorStyleTags($registry);
        $scripts = $assets->vendorScriptTags($registry);
        $hash = $assets->hashIn($this->dirA, 'style.css');

        $this->assertStringContainsString('href="/system-x/vendor/example/style.'.$hash.'.css"', $styles);
        $this->assertSame('', $scripts, 'a null js half emits no script tag');
    }
}

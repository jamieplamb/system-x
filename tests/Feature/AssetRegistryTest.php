<?php

namespace Tests\Feature;

use InvalidArgumentException;
use SystemX\Core\Support\AssetRegistry;
use Tests\TestCase;

class AssetRegistryTest extends TestCase
{
    public function test_it_stores_and_returns_a_bundle(): void
    {
        $registry = new AssetRegistry;
        $registry->register('example', '/abs/dist', js: 'example-todo.js', css: 'example-todo.css');

        $this->assertTrue($registry->has('example'));
        $this->assertSame(
            ['dir' => '/abs/dist', 'js' => 'example-todo.js', 'css' => 'example-todo.css'],
            $registry->get('example'),
        );
        $this->assertSame(['example'], $registry->namespaces());
        $this->assertArrayHasKey('example', $registry->all());
    }

    public function test_a_null_js_or_css_is_allowed(): void
    {
        $registry = new AssetRegistry;
        $registry->register('cssonly', '/abs/dist', css: 'theme.css');

        $this->assertSame(['dir' => '/abs/dist', 'js' => null, 'css' => 'theme.css'], $registry->get('cssonly'));
    }

    public function test_get_returns_null_for_an_unknown_namespace(): void
    {
        $this->assertNull((new AssetRegistry)->get('nope'));
    }

    public function test_a_duplicate_namespace_throws(): void
    {
        $registry = new AssetRegistry;
        $registry->register('example', '/abs/dist', js: 'a.js');

        $this->expectException(InvalidArgumentException::class);
        $registry->register('example', '/other/dist', js: 'b.js');
    }

    public function test_it_is_bound_as_a_singleton(): void
    {
        $this->assertSame(
            $this->app->make(AssetRegistry::class),
            $this->app->make(AssetRegistry::class),
        );
    }
}

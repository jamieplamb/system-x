<?php

namespace Tests\Feature;

use SystemX\Core\Runtime\AppRegistry;
use Tests\TestCase;

// The whole third-party thesis in one test: NOTHING in the host registered example.todo -- the
// example/todo-app package's OWN provider did, via Laravel auto-discovery. If it's in the registry
// metadata, a third party can ship an app as a composer package with zero host code. See the
// shipping-a-system-x-app skill + packages/example-todo.
class ThirdPartyAppTest extends TestCase
{
    public function test_a_third_party_package_app_is_auto_discovered_and_registered(): void
    {
        $meta = app(AppRegistry::class)->metadata();
        $slugs = array_column($meta, 'slug');
        $this->assertContains('example.todo', $slugs);

        $todo = collect($meta)->firstWhere('slug', 'example.todo');
        $this->assertSame('Todo', $todo['title']);
        $this->assertFalse($todo['system']); // a user app -> appears in the launcher grid
    }
}

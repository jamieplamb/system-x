<?php

namespace SystemX\Core\Tests\Runtime;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SystemX\Core\Runtime\App;
use SystemX\Core\Runtime\AppRegistry;
use SystemX\Core\Wire\Node;

// A throwaway App subclass so the registry test stands on its own -- ToyApp lives in
// another test file, so PSR-4 won't autoload it across files.
class RegistryFixtureApp extends App
{
    public function slug(): string
    {
        return 'toy';
    }

    public function render(): Node
    {
        return new Node('window');
    }
}

class AppRegistryTest extends TestCase
{
    public function test_it_resolves_a_registered_slug_to_its_app_class(): void
    {
        $registry = new AppRegistry;
        $registry->register('toy', RegistryFixtureApp::class);

        $this->assertTrue($registry->has('toy'));
        $this->assertInstanceOf(RegistryFixtureApp::class, $registry->resolve('toy'));
        $this->assertSame(['toy'], $registry->slugs());
    }

    public function test_an_unknown_slug_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AppRegistry)->resolve('nope');
    }

    public function test_registering_a_duplicate_slug_throws(): void
    {
        $registry = new AppRegistry;
        $registry->register('toy', RegistryFixtureApp::class);

        $this->expectException(InvalidArgumentException::class);
        $registry->register('toy', RegistryFixtureApp::class);
    }
}

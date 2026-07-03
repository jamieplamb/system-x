<?php

namespace SystemX\Core\Tests\Wire;

use PHPUnit\Framework\TestCase;
use SystemX\Core\Widgets\Button;
use SystemX\Core\Wire\WidgetRegistry;

class WidgetRegistryTest extends TestCase
{
    public function test_it_registers_and_resolves_a_type_to_its_builder(): void
    {
        $registry = new WidgetRegistry;
        $registry->register('button', Button::class);

        $this->assertTrue($registry->has('button'));
        $this->assertSame(Button::class, $registry->builderFor('button'));
        $this->assertContains('button', $registry->types());
    }

    public function test_builder_for_an_unknown_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WidgetRegistry)->builderFor('nope');
    }

    public function test_registering_a_duplicate_type_throws(): void
    {
        $registry = new WidgetRegistry;
        $registry->register('button', Button::class);

        $this->expectException(\InvalidArgumentException::class);
        $registry->register('button', Button::class);
    }
}

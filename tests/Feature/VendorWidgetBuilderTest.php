<?php

namespace Tests\Feature;

use Example\TodoApp\Widgets\Gauge;
use Tests\TestCase;

class VendorWidgetBuilderTest extends TestCase
{
    public function test_it_builds_an_example_gauge_node(): void
    {
        $gauge = Gauge::make(7)->id('g');

        $this->assertSame('example.gauge', $gauge->type);
        $this->assertSame('g', $gauge->id);
        $this->assertSame(7, $gauge->props['value']);
        $this->assertArrayHasKey('events', $gauge->props);
    }

    public function test_binding_a_click_opens_the_events_allowlist(): void
    {
        $gauge = Gauge::make(0)->id('g')->on('click', fn () => null);

        $this->assertContains('click', $gauge->props['events']);
    }
}

<?php

namespace SystemX\Core\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SystemX\Core\Runtime\WidgetEvent;

class WidgetEventTest extends TestCase
{
    public function test_it_holds_the_four_components(): void
    {
        $event = new WidgetEvent('note', 'change', 'typed text', ['extra' => 1]);

        $this->assertSame('note', $event->widgetId);
        $this->assertSame('change', $event->event);
        $this->assertSame('typed text', $event->value);
        $this->assertSame(['extra' => 1], $event->payload);
    }

    public function test_value_and_payload_default_to_null_and_empty(): void
    {
        $event = new WidgetEvent('clicker', 'click');

        $this->assertNull($event->value);
        $this->assertSame([], $event->payload);
    }

    public function test_typed_accessors_coerce_every_incoming_shape_safely(): void
    {
        $this->assertSame('5', (new WidgetEvent('w', 'e', 5))->asString());
        $this->assertSame('', (new WidgetEvent('w', 'e', ['x']))->asString());   // array -> '' (no "Array" warning)
        $this->assertSame('', (new WidgetEvent('w', 'e', null))->asString());
        $this->assertSame(50, (new WidgetEvent('w', 'e', '50'))->asInt());
        $this->assertSame(0, (new WidgetEvent('w', 'e', ['x']))->asInt());
        $this->assertTrue((new WidgetEvent('w', 'e', true))->asBool());
        $this->assertTrue((new WidgetEvent('w', 'e', 'on'))->asBool());          // truthy string
        $this->assertFalse((new WidgetEvent('w', 'e', ''))->asBool());           // empty string falsy
        $this->assertSame(['a'], (new WidgetEvent('w', 'e', ['a']))->asArray());
        $this->assertSame([], (new WidgetEvent('w', 'e', 'x'))->asArray());       // non-array -> []
        $this->assertSame(1.5, (new WidgetEvent('w', 'e', '1.5'))->asFloat());
    }

    public function test_construction_guard_normalises_non_json_shapes_to_null(): void
    {
        // an object can't come from json_decode-as-array, but the guard is defensive
        $this->assertNull((new WidgetEvent('w', 'e', new \stdClass))->value);
        $this->assertSame('ok', (new WidgetEvent('w', 'e', 'ok'))->value); // scalars pass through
        $this->assertSame(['a' => 1], (new WidgetEvent('w', 'e', ['a' => 1]))->value); // arrays pass through
    }
}

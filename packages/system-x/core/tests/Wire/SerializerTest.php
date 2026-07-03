<?php

namespace SystemX\Core\Tests\Wire;

use PHPUnit\Framework\TestCase;
use SystemX\Core\Widgets\Button;
use SystemX\Core\Widgets\Label;
use SystemX\Core\Widgets\Window;
use SystemX\Core\Wire\Node;
use SystemX\Core\Wire\Serializer;

class SerializerTest extends TestCase
{
    public function test_it_serializes_a_window_tree_to_a_nested_array(): void
    {
        $tree = Window::make('Hello')->size(320, 160)->content([
            Label::make('Clicked 0 times')->id('counter'),
            Button::make('Click me')->id('clicker'),
        ]);

        $result = (new Serializer)->serialize($tree);

        $this->assertSame('window', $result['type']);
        $this->assertSame('Hello', $result['props']['title']);
        $this->assertSame([320, 160], [$result['props']['width'], $result['props']['height']]);
        $this->assertCount(2, $result['children']);

        $this->assertSame('label', $result['children'][0]['type']);
        $this->assertSame('counter', $result['children'][0]['id']);
        $this->assertSame('Clicked 0 times', $result['children'][0]['props']['text']);

        $this->assertSame('button', $result['children'][1]['type']);
        $this->assertSame('clicker', $result['children'][1]['id']);
        $this->assertSame('Click me', $result['children'][1]['props']['label']);
    }

    public function test_a_node_with_no_props_encodes_props_as_a_json_object_not_an_array(): void
    {
        $json = json_encode((new Serializer)->serialize(new Node('spacer')));

        // The wire contract: props is always an object. An empty-props node must be
        // `"props":{}` (not `"props":[]`), while children stays a JSON array.
        $this->assertStringContainsString('"props":{}', $json);
        $this->assertStringContainsString('"children":[]', $json);
    }
}

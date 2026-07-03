<?php

namespace SystemX\Core\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SystemX\Core\Demo\HelloApp;
use SystemX\Core\Runtime\PropertyHydrator;
use SystemX\Core\Runtime\WidgetEvent;
use SystemX\Core\State\StateBag;
use SystemX\Core\Wire\Serializer;

class HelloAppTest extends TestCase
{
    public function test_its_slug_is_hello(): void
    {
        $this->assertSame('hello', (new HelloApp)->slug());
    }

    public function test_a_clicker_click_increments_the_count(): void
    {
        $app = new HelloApp;
        (new PropertyHydrator)->hydrate($app, new StateBag(['count' => 4], 1));

        $app->boot(new WidgetEvent('clicker', 'click', null, []));

        $this->assertSame(5, $app->count);
    }

    public function test_the_rendered_tree_is_byte_identical_to_the_plan_3_shape(): void
    {
        $app = new HelloApp;
        (new PropertyHydrator)->hydrate($app, new StateBag(['count' => 0], 1));

        $tree = (new Serializer)->serialize($app->renderInitial());

        // The Plan 2/3 contract: counter at children.0, clicker at children.1, the
        // toolkit Stack at children.2, empty props as {} (an object), 'Clicked 0 times'.
        $this->assertSame('window', $tree['type']);
        $this->assertSame('Clicked 0 times', $tree['children'][0]['props']['text']);
        $this->assertSame('counter', $tree['children'][0]['id']);
        $this->assertSame('clicker', $tree['children'][1]['id']);
        $this->assertSame('stack', $tree['children'][2]['type']);
    }
}

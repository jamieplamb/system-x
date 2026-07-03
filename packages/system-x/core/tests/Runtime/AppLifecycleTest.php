<?php

namespace SystemX\Core\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SystemX\Core\Runtime\App;
use SystemX\Core\Runtime\PropertyHydrator;
use SystemX\Core\Runtime\WidgetEvent;
use SystemX\Core\State\StateBag;
use SystemX\Core\Widgets\Button;
use SystemX\Core\Widgets\Label;
use SystemX\Core\Widgets\Window;
use SystemX\Core\Wire\Node;

class ToyApp extends App
{
    public int $count = 0;

    public function slug(): string
    {
        return 'toy';
    }

    public function render(): Node
    {
        return Window::make('Toy')->content([
            Label::make("Count {$this->count}")->id('label'),
            // Named-handler binding: clicker -> increment().
            $this->button('Click', 'clicker')->handles('increment'),
        ]);
    }

    public function increment(): void
    {
        $this->count++;
    }

    // Helper so the test reads cleanly -- in real apps the builder carries the binding.
    private function button(string $label, string $id): Button
    {
        return Button::make($label)->id($id);
    }
}

class AppLifecycleTest extends TestCase
{
    public function test_boot_hydrates_dispatches_and_leaves_the_mutated_state_dehydratable(): void
    {
        $app = new ToyApp;
        $hydrator = new PropertyHydrator;

        // Seed: count = 4 in the bag.
        $hydrator->hydrate($app, new StateBag(['count' => 4], 1));

        // Boot with a click on the clicker -> increment() runs against the hydrated $this.
        $tree = $app->boot(new WidgetEvent('clicker', 'click', null, []));

        // The dispatch mutated the live property...
        $this->assertSame(5, $app->count);
        // ...and the re-rendered tree reflects it.
        $this->assertSame('Count 5', $tree->children[0]->props['text']);
        // ...and dehydrate yields the mutated bag (count only -- base fields excluded).
        $this->assertSame(['count' => 5], $hydrator->dehydrate($app));
    }

    public function test_a_forged_widget_id_is_a_no_op(): void
    {
        $app = new ToyApp;
        (new PropertyHydrator)->hydrate($app, new StateBag(['count' => 4], 1));

        $app->boot(new WidgetEvent('forged-id', 'click', null, []));

        $this->assertSame(4, $app->count); // unchanged -- the forged id matched no handler
    }
}

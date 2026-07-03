<?php

namespace SystemX\Core\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SystemX\Core\Runtime\BoundHandler;
use SystemX\Core\Runtime\WidgetEvent;

class BoundHandlerHost
{
    public int $count = 0;

    public ?WidgetEvent $lastEvent = null;

    public function increment(): void
    {
        $this->count++;
    }

    public function record(WidgetEvent $event): void
    {
        $this->lastEvent = $event;
        $this->count++;
    }
}

class BoundHandlerTest extends TestCase
{
    public function test_a_named_method_binds_to_the_live_instance_and_mutates_it(): void
    {
        $host = new BoundHandlerHost;
        $handler = BoundHandler::from($host, 'increment');

        $handler(new WidgetEvent('clicker', 'click', null, []));

        $this->assertSame(1, $host->count); // the bound method mutated the real $this
    }

    public function test_an_inline_closure_binds_to_the_live_instance(): void
    {
        $host = new BoundHandlerHost;
        // The closure references $this via binding -- the App ergonomics of D1.
        $handler = BoundHandler::from($host, function (): void {
            $this->count += 5;
        });

        $handler(new WidgetEvent('clicker', 'click', null, []));

        $this->assertSame(5, $host->count);
    }

    public function test_a_zero_arg_handler_is_invoked_without_the_event(): void
    {
        $host = new BoundHandlerHost;
        $handler = BoundHandler::from($host, 'increment'); // increment() takes no args

        $handler(new WidgetEvent('clicker', 'click', 'ignored', []));

        $this->assertSame(1, $host->count); // invoked despite the event being passed in
    }

    public function test_a_one_widget_event_arg_handler_receives_the_event(): void
    {
        $host = new BoundHandlerHost;
        $handler = BoundHandler::from($host, 'record'); // record(WidgetEvent $e)
        $event = new WidgetEvent('note', 'change', 'typed text', []);

        $handler($event);

        $this->assertSame($event, $host->lastEvent); // the VO arrived intact
        $this->assertSame('typed text', $host->lastEvent->value);
    }
}

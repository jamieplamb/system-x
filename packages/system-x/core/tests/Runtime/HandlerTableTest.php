<?php

namespace SystemX\Core\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SystemX\Core\Runtime\BoundHandler;
use SystemX\Core\Runtime\HandlerTable;
use SystemX\Core\Runtime\WidgetEvent;

class HandlerTableTest extends TestCase
{
    public function test_dispatch_invokes_the_bound_handler_for_a_known_pair(): void
    {
        $host = new BoundHandlerHost;
        $table = new HandlerTable;
        $table->bind('clicker', 'click', BoundHandler::from($host, 'increment'));

        $table->dispatch('clicker', 'click', new WidgetEvent('clicker', 'click', null, []));

        $this->assertSame(1, $host->count);
    }

    public function test_dispatch_returns_null_for_an_unknown_pair_without_throwing(): void
    {
        $table = new HandlerTable;

        // A forged / stale (widgetId, event) -- not produced by this render. The
        // freshly rebuilt table IS the allowlist; an unknown target drops silently.
        $this->assertNull(
            $table->dispatch('forged', 'click', new WidgetEvent('forged', 'click', null, [])),
        );
    }

    public function test_a_known_widget_but_unknown_event_also_drops_silently(): void
    {
        $host = new BoundHandlerHost;
        $table = new HandlerTable;
        $table->bind('clicker', 'click', BoundHandler::from($host, 'increment'));

        $this->assertNull(
            $table->dispatch('clicker', 'dblclick', new WidgetEvent('clicker', 'dblclick', null, [])),
        );
        $this->assertSame(0, $host->count); // never invoked
    }
}

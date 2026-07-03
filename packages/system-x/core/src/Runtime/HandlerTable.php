<?php

namespace SystemX\Core\Runtime;

// A PER-REBUILD dispatch table keyed (widgetId, event) -> BoundHandler (D1). Built
// fresh every time the App renders, so it always reflects the CURRENT tree -- which
// makes it the per-event validation allowlist: a (widgetId, event) the latest render
// did not bind is forged or stale and dispatch() drops it silently (no throw).
class HandlerTable
{
    /** @var array<string, BoundHandler> */
    private array $handlers = [];

    public function bind(string $widgetId, string $event, BoundHandler $handler): void
    {
        $this->handlers[$this->key($widgetId, $event)] = $handler;
    }

    public function dispatch(string $widgetId, string $event, WidgetEvent $payload): mixed
    {
        $handler = $this->handlers[$this->key($widgetId, $event)] ?? null;

        return $handler?->__invoke($payload);
    }

    private function key(string $widgetId, string $event): string
    {
        return $widgetId.'@'.$event;
    }
}

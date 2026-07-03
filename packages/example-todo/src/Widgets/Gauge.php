<?php

namespace Example\TodoApp\Widgets;

use SystemX\Core\Wire\Node;

// A custom third-party widget -- a new wire TYPE (example.gauge) core knows nothing about. It
// serializes type-agnostically (the Serializer doesn't gate on WidgetRegistry), reaching the client
// as {type:'example.gauge', props:{value, events}, id}. Its JS renderer ships in the package's own
// dist bundle (see dist/example-todo.js) and registers before first render via the vendor-asset seam.
// This is the canonical template a pro pack (datagrid/tree) follows. The `value` is display; a bound
// click (App::on('click', ...)) round-trips through the delegated dispatcher like a Button.
class Gauge extends Node
{
    public function __construct(int $value)
    {
        // Seed events as an empty allowlist -- a caller opens 'click' by binding a handler
        // (->on('click', ...)), matching how core's stateful builders seed their allowlist.
        parent::__construct('example.gauge', ['value' => $value, 'events' => []]);
    }

    public static function make(int $value): static
    {
        return new static($value);
    }
}

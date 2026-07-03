<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// A tab strip + panels. The strip is a RadioGroup wearing a tab skin (one hidden radio
// per tab, the change round-trips the active tab value); the panels are the children,
// shown one at a time (GroupBox body-host). Durable: the app passes its own persisted
// property into ->active() and writes it back in ->onChange(); the framework persists +
// rehydrates that property (the widget itself is stateless per render). Panels pair to
// the `tabs` map BY ORDER.
class Tabs extends Node
{
    public function __construct()
    {
        parent::__construct('tabs', [
            'tabs' => [],
            'active' => '',
            'events' => ['change'],
        ]);
    }

    public static function make(): static
    {
        return new static;
    }

    /** @param array<string, string> $tabs */
    public function tabs(array $tabs): static
    {
        $this->props['tabs'] = $tabs;

        return $this;
    }

    public function active(string $value): static
    {
        $this->props['active'] = $value;

        return $this;
    }

    public function onChange(callable|string|null $handler = null): static
    {
        return $handler === null ? $this->withEvent('change') : $this->on('change', $handler);
    }
}

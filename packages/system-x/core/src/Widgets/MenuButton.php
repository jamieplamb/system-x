<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// A button that opens an anchored dropdown menu. `items` is DATA (not child widgets): a list of
// ['label'=>.., 'value'=>.., 'disabled'?=>bool, 'danger'?=>bool, 'divider'?=>bool,
// 'accel'?=>str]. Picking an item emits 'select' with its value -> onSelect. The popup portals to
// document.body (it must escape the window's overflow:hidden); open-state is transient (client-
// owned), only the pick round-trips. See the design spec, slice 3b.
class MenuButton extends Node
{
    public function __construct(string $label)
    {
        parent::__construct('menu', [
            'label' => $label,
            'items' => [],
            'events' => ['select'],
        ]);
    }

    public static function make(string $label): static
    {
        return new static($label);
    }

    /** @param array<int, array<string, mixed>> $items */
    public function items(array $items): static
    {
        $this->props['items'] = $items;

        return $this;
    }

    public function onSelect(callable|string|null $handler = null): static
    {
        return $handler === null ? $this->withEvent('select') : $this->on('select', $handler);
    }
}

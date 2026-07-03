<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// The classic horizontal menu bar (File/Edit/View ...). `menus` is DATA: a list of
// ['label'=>.., 'items'=>[ <item>, ... ]] where each item is the MenuButton item shape. A pick
// (from any menu) emits 'select' with the item's value -> onSelect. Labels open anchored dropdowns
// (the shared overlay helper); once one is open, hovering a sibling label switches to it. Open-
// state is transient (client-owned). See the design spec, slice 3b.
class MenuBar extends Node
{
    public function __construct()
    {
        parent::__construct('menubar', [
            'menus' => [],
            'events' => ['select'],
        ]);
    }

    public static function make(): static
    {
        return new static;
    }

    /** @param array<int, array<string, mixed>> $menus */
    public function menus(array $menus): static
    {
        $this->props['menus'] = $menus;

        return $this;
    }

    public function onSelect(callable|string|null $handler = null): static
    {
        return $handler === null ? $this->withEvent('select') : $this->on('select', $handler);
    }
}

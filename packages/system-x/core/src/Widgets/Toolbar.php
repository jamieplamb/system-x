<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// A raised horizontal strip of actions -- a styled CONTAINER. Its children (Buttons,
// Separators, any widget) reconcile positionally like Stack/Box; each child round-trips
// its own events (Button::handles), so Toolbar itself has NO events. Its value is the
// raised-strip styling + horizontal layout + hosting Separators between action groups.
class Toolbar extends Node
{
    public function __construct()
    {
        parent::__construct('toolbar');
    }

    public static function make(): static
    {
        return new static;
    }
}

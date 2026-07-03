<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// A window-modal dialog. Declarative: `open` (a durable app property) decides whether the
// overlay shows; the client mounts an in-content backdrop + centred panel when true and
// renders nothing when false. `content` is the body (any widgets, incl. the app's own OK/
// Cancel buttons), reconciled via the body-host pattern. Dismiss (the client's close button,
// Escape, backdrop click) fires the 'close' event -> onClose, which typically clears `open`.
// `dismissible(false)` drops the close button + makes Escape/backdrop inert (a must-decide
// dialog). The overlay lives inside the window content, so it moves/resizes/stacks with the
// window for free -- no portal, no global z-tiers. See the design spec, slice 3a.
class Dialog extends Node
{
    public function __construct()
    {
        parent::__construct('dialog', [
            'open' => false,
            'title' => '',
            'dismissible' => true,
            'events' => ['close'],
        ]);
    }

    public static function make(): static
    {
        return new static;
    }

    public function open(bool $open = true): static
    {
        $this->props['open'] = $open;

        return $this;
    }

    public function title(string $title): static
    {
        $this->props['title'] = $title;

        return $this;
    }

    public function dismissible(bool $dismissible = true): static
    {
        $this->props['dismissible'] = $dismissible;

        return $this;
    }

    public function onClose(callable|string|null $handler = null): static
    {
        return $handler === null ? $this->withEvent('close') : $this->on('close', $handler);
    }
}

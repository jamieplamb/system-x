<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// Wraps a child and shows a small hint bubble on hover/focus. `text` is the hint, `side` places
// the bubble (top default / bottom / left / right). Display-only: NO events, nothing round-trips.
// The bubble is a LOCAL CSS overlay (not portaled) revealed by pure :hover/:focus-within -- the
// renderer attaches no listeners. Being local means a bubble at a window's overflow:hidden edge
// can clip (accepted v1 tradeoff; keep hints short and prefer the roomier side). `content` is the
// wrapped widget(s), reconciled via the body-host pattern. See the design spec, slice 3c.
class Tooltip extends Node
{
    private const SIDES = ['top', 'bottom', 'left', 'right'];

    public function __construct(string $text)
    {
        parent::__construct('tooltip', [
            'text' => $text,
            'side' => 'top',
        ]);
    }

    public static function make(string $text): static
    {
        return new static($text);
    }

    public function side(string $side): static
    {
        // Fail loudly at build time: an unknown side would stamp a data-side matching no CSS
        // positional rule, silently rendering the bubble on top of the wrapped child.
        if (! in_array($side, self::SIDES, true)) {
            throw new \InvalidArgumentException(
                "system-x: Tooltip side must be one of top|bottom|left|right, got \"{$side}\"."
            );
        }
        $this->props['side'] = $side;

        return $this;
    }
}

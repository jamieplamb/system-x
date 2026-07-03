<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// The framework's deliberate ESCAPE HATCH (D7). Renders arbitrary developer-
// supplied HTML inside a .sx-raw container. system-x does NOT sanitise props.html
// -- exactly like React's dangerouslySetInnerHTML, the XSS responsibility sits with
// the APP AUTHOR. raw must NEVER be fed unescaped end-user input. The morph treats
// raw as OPAQUE (a leaf): no keyed-morph inside, replace-on-change only, and it has
// NO interaction contract (no props.events).
class Raw extends Node
{
    public function __construct()
    {
        parent::__construct('raw', ['html' => '']);
    }

    public static function make(): static
    {
        return new static;
    }

    public function html(string $html): static
    {
        $this->props['html'] = $html;

        return $this;
    }
}

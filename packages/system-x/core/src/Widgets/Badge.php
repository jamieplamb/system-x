<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// Inline status pill -- display-only, no interaction contract (no events allowlist).
// `tone` is a fixed vocabulary the CSS keys off (.sx-badge--{tone}); an unknown tone
// just yields an unstyled pill, so no server-side validation is needed.
class Badge extends Node
{
    public function __construct(string $text)
    {
        parent::__construct('badge', ['text' => $text, 'tone' => 'neutral']);
    }

    public static function make(string $text): static
    {
        return new static($text);
    }

    public function tone(string $tone): static
    {
        $this->props['tone'] = $tone;

        return $this;
    }
}

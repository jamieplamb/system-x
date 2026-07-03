<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// A divider rule -- display-only, no interaction contract. Orientation drives
// .sx-separator--{orientation}; horizontal (full-width) is the default.
class Separator extends Node
{
    public function __construct()
    {
        parent::__construct('separator', ['orientation' => 'horizontal']);
    }

    public static function make(): static
    {
        return new static;
    }

    public function vertical(): static
    {
        $this->props['orientation'] = 'vertical';

        return $this;
    }
}

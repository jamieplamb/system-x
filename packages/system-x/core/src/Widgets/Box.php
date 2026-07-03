<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// Layout container: a horizontal ROW (the complement to Stack's column). Structural only --
// no surface/bevel; it inherits the content area it sits in. Children reconcile positionally.
class Box extends Node
{
    public function __construct()
    {
        parent::__construct('box');
    }

    public static function make(): static
    {
        return new static;
    }
}

<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

class Stack extends Node
{
    public function __construct()
    {
        parent::__construct('stack');
    }

    public static function make(): static
    {
        return new static;
    }
}

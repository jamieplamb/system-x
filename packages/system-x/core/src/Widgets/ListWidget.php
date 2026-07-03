<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// Wire type is 'list'. Class is ListWidget (List reads badly / is awkward as a class name).
class ListWidget extends Node
{
    public function __construct()
    {
        parent::__construct('list');
    }

    public static function make(): static
    {
        return new static;
    }
}

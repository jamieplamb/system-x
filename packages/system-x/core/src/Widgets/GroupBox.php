<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// Titled container for related controls -- renders <fieldset> + <legend>. Children
// ride the inherited Node::content() and reconcile positionally like Stack. Display-
// only: no interaction contract.
class GroupBox extends Node
{
    public function __construct(string $legend)
    {
        parent::__construct('groupbox', ['legend' => $legend]);
    }

    public static function make(string $legend): static
    {
        return new static($legend);
    }
}

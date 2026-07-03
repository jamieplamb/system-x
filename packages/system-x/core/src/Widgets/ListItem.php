<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

class ListItem extends Node
{
    public function __construct(string $text)
    {
        parent::__construct('listitem', ['text' => $text]);
    }

    public static function make(string $text): static
    {
        return new static($text);
    }

    // props.key is the RECONCILIATION identity (D3) -- a stable, developer-supplied
    // record id. Distinct from node->id (the event-addressing handle). Required on
    // List children so the morph can match rows across reorder/insert/delete.
    public function key(string $key): static
    {
        $this->props['key'] = $key;

        return $this;
    }
}

<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

class Label extends Node
{
    public function __construct(string $text)
    {
        parent::__construct('label', ['text' => $text]);
    }

    public static function make(string $text): static
    {
        return new static($text);
    }
}

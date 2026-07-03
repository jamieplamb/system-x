<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

class Window extends Node
{
    public function __construct(string $title)
    {
        parent::__construct('window', ['title' => $title, 'width' => 400, 'height' => 300]);
    }

    public static function make(string $title): static
    {
        return new static($title);
    }

    public function size(int $width, int $height): static
    {
        $this->props['width'] = $width;
        $this->props['height'] = $height;

        return $this;
    }
}

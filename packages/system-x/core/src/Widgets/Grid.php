<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// Layout container: a CSS GRID. The columns prop drives grid-template-columns: repeat(N, 1fr)
// (the renderer sets it inline). Structural only -- no surface/bevel; children on the token
// gap. Default 2 columns; the setter clamps to >= 1 (repeat(0, 1fr) is invalid CSS).
class Grid extends Node
{
    public function __construct()
    {
        parent::__construct('grid', ['columns' => 2]);
    }

    public static function make(): static
    {
        return new static;
    }

    public function columns(int $columns): static
    {
        $this->props['columns'] = max(1, $columns);

        return $this;
    }
}

<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// Hand-rolled SVG chart (line/bar/area). Display-only, no events allowlist -- the hover tooltip is
// client-side. One chart TYPE per widget; N series share the x-axis categories by index.
class Chart extends Node
{
    public function __construct()
    {
        parent::__construct('chart', ['type' => 'line', 'categories' => [], 'series' => [], 'height' => 220]);
    }

    public static function make(): static
    {
        return new static;
    }

    public function line(): static
    {
        $this->props['type'] = 'line';

        return $this;
    }

    public function bar(): static
    {
        $this->props['type'] = 'bar';

        return $this;
    }

    public function area(): static
    {
        $this->props['type'] = 'area';

        return $this;
    }

    /** @param array<int, string> $categories */
    public function categories(array $categories): static
    {
        $this->props['categories'] = array_values($categories);

        return $this;
    }

    /** @param array<int, int|float|null> $data */
    public function series(string $label, array $data): static
    {
        $this->props['series'][] = ['label' => $label, 'data' => array_values($data)];

        return $this;
    }

    public function height(int $px): static
    {
        $this->props['height'] = $px;

        return $this;
    }
}

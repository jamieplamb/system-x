<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// Determinate progress (value 0-100) or an indeterminate sweep. Display-only.
class ProgressBar extends Node
{
    public function __construct()
    {
        parent::__construct('progressbar', [
            'value' => 0,
            'indeterminate' => false,
            'label' => null,
        ]);
    }

    public static function make(): static
    {
        return new static;
    }

    public function value(int $value): static
    {
        $this->props['value'] = max(0, min(100, $value));

        return $this;
    }

    public function indeterminate(bool $on = true): static
    {
        $this->props['indeterminate'] = $on;

        return $this;
    }

    public function label(string $label): static
    {
        $this->props['label'] = $label;

        return $this;
    }
}

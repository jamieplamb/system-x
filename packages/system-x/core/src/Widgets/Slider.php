<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// Native <input type="range"> in a styled wrapper. Numeric value with min/max/step.
// Rides the input seam: change is the default round-trip and the native DOM `change`
// fires on RELEASE (the dispatcher listens for change, not the live `input` event), so
// no drag-spam. The dispatcher echoes the value as a STRING; an app binding a Slider to
// an int property gets it coerced by PropertyHydrator (no per-widget cast). No value
// clamping server-side -- the native input enforces min/max/step client-side.
class Slider extends Node
{
    public function __construct(string $label)
    {
        parent::__construct('slider', [
            'label' => $label,
            'min' => 0,
            'max' => 100,
            'step' => 1,
            'value' => 0,
            'events' => ['change'],
        ]);
    }

    public static function make(string $label): static
    {
        return new static($label);
    }

    public function min(int $min): static
    {
        $this->props['min'] = $min;

        return $this;
    }

    public function max(int $max): static
    {
        $this->props['max'] = $max;

        return $this;
    }

    public function step(int $step): static
    {
        $this->props['step'] = $step;

        return $this;
    }

    public function value(int $value): static
    {
        $this->props['value'] = $value;

        return $this;
    }

    public function onChange(callable|string|null $handler = null): static
    {
        return $handler === null ? $this->withEvent('change') : $this->on('change', $handler);
    }
}

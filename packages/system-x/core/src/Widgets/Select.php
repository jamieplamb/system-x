<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// Native <select> in a styled wrapper. options is value=>label; value is the selected
// key. Rides the input seam: change is the default round-trip event; the dispatcher
// echoes the selected option's value keyed by the wrapper's data-sx-id.
class Select extends Node
{
    public function __construct(string $label)
    {
        parent::__construct('select', [
            'label' => $label,
            'options' => [],
            'value' => '',
            'events' => ['change'],
        ]);
    }

    public static function make(string $label): static
    {
        return new static($label);
    }

    /** @param array<string, string> $options */
    public function options(array $options): static
    {
        $this->props['options'] = $options;

        return $this;
    }

    public function value(string $value): static
    {
        $this->props['value'] = $value;

        return $this;
    }

    public function onChange(callable|string|null $handler = null): static
    {
        return $handler === null ? $this->withEvent('change') : $this->on('change', $handler);
    }
}

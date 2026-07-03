<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// A group of inline radios sharing one name, with one bound value -- mirrors Select
// (options value=>label + a selected value), rendered as visible radios instead of a
// dropdown. ONE widget, not a parent+children pair: the renderer builds N radios from
// options. Rides the input seam: change is the default round-trip; the dispatcher
// echoes the checked radio's value keyed by the group wrapper's data-sx-id.
class RadioGroup extends Node
{
    public function __construct(string $label)
    {
        parent::__construct('radiogroup', [
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

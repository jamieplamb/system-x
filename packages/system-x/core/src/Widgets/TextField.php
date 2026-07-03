<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

class TextField extends Node
{
    public function __construct(string $name)
    {
        // events is the interaction-contract allowlist (D4): which events round-trip.
        // Default: submit only (Enter). Keystrokes/caret stay local with zero POST.
        parent::__construct('textfield', [
            'name' => $name,
            'value' => '',
            'events' => ['submit'],
        ]);
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function value(string $value): static
    {
        $this->props['value'] = $value;

        return $this;
    }

    // Pass a handler to bind it (sugar for ->on('submit', $fn), matching Button); call
    // it bare to only open 'submit' on the props.events round-trip allowlist.
    public function onSubmit(callable|string|null $handler = null): static
    {
        return $handler === null
            ? $this->withEvent('submit')
            : $this->on('submit', $handler);
    }

    // Same shape as onSubmit(): a handler binds via Node::on(); bare only opens the
    // 'change' round-trip.
    public function onChange(callable|string|null $handler = null): static
    {
        return $handler === null
            ? $this->withEvent('change')
            : $this->on('change', $handler);
    }
}

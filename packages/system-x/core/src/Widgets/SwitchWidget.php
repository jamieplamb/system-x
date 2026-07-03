<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// A pill-styled boolean toggle -- Checkbox's re-skinned cousin. Same interaction
// contract: a real <input type="checkbox"> underneath, props.events the round-trip
// allowlist, the dispatcher echoes `checked` keyed by data-sx-id. The class is
// SwitchWidget because `switch` is a PHP reserved word (cf. ListWidget); the wire
// type stays 'switch'.
class SwitchWidget extends Node
{
    public function __construct(string $label)
    {
        parent::__construct('switch', [
            'label' => $label,
            'checked' => false,
            'events' => ['change'],
        ]);
    }

    public static function make(string $label): static
    {
        return new static($label);
    }

    public function checked(bool $checked = true): static
    {
        $this->props['checked'] = $checked;

        return $this;
    }

    // Mirrors Checkbox::onChange(): pass a handler to bind it (sugar for
    // ->on('change', $fn)); call it bare to re-affirm 'change' on the
    // round-trip allowlist (it is already the default).
    public function onChange(callable|string|null $handler = null): static
    {
        return $handler === null
            ? $this->withEvent('change')
            : $this->on('change', $handler);
    }
}

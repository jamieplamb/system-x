<?php

namespace SystemX\Core\Widgets;

use SystemX\Core\Wire\Node;

// A small stateful leaf input -- TextField's simpler cousin. It rides the SAME
// interaction contract (D4): props.events is the round-trip allowlist read by the
// shared delegated dispatcher. Default: change only -- a toggle is a discrete
// commit, unlike per-keystroke typing, so there is no submit/keystroke case.
// Note: unlike TextField, Checkbox carries NO `name` prop -- this is deliberate.
// Addressing is id-based (data-sx-id); the Task 7 dispatcher echoes `checked`
// keyed by the node id, so a `name` attribute would be redundant.
class Checkbox extends Node
{
    public function __construct(string $label)
    {
        parent::__construct('checkbox', [
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

    // Mirrors TextField::onChange(): pass a handler to bind it (sugar for
    // ->on('change', $fn), matching Button); call it bare to only re-affirm 'change'
    // on the round-trip allowlist (it is already the default).
    public function onChange(callable|string|null $handler = null): static
    {
        return $handler === null
            ? $this->withEvent('change')
            : $this->on('change', $handler);
    }
}

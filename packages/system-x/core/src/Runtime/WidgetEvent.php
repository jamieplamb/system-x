<?php

namespace SystemX\Core\Runtime;

// The ONE value a handler optionally receives (D1). widgetId + event address the
// interaction; value is the live field value (TextField string / Checkbox bool);
// payload is an open map for any extra wire data. Immutable -- a handler reads it,
// it never mutates it. NOT Livewire-style stringly-typed positional args.
class WidgetEvent
{
    public readonly mixed $value;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $widgetId,
        public readonly string $event,
        mixed $value = null,
        public readonly array $payload = [],
    ) {
        // Guard: keep value to a JSON-sane shape. Anything else (an object, a resource --
        // not producible by json_decode, defensive) becomes null so handlers never choke.
        $this->value = (is_scalar($value) || is_array($value) || $value === null) ? $value : null;
    }

    // Safe typed accessors -- treat $event->value as UNTRUSTED, client-controlled input.
    public function asString(): string
    {
        return is_scalar($this->value) ? (string) $this->value : '';
    }

    public function asInt(): int
    {
        return is_scalar($this->value) ? (int) $this->value : 0;
    }

    public function asFloat(): float
    {
        return is_scalar($this->value) ? (float) $this->value : 0.0;
    }

    public function asBool(): bool
    {
        return is_array($this->value) ? $this->value !== [] : (bool) $this->value;
    }

    /** @return array<mixed> */
    public function asArray(): array
    {
        return is_array($this->value) ? $this->value : [];
    }
}

<?php

namespace SystemX\Core\Wire;

class Node
{
    /** @var array<int, Node> */
    public array $children = [];

    // Handler bindings recorded by on()/onClick()/handles() (D1/S2). DELIBERATELY
    // not part of the wire: the serializer reads only type/id/props/children, so this
    // field is dropped for free and the wire stays byte-identical. Each entry is a
    // [event, handler] pair; App::collectBindings() walks the tree draining these into
    // a per-rebuild HandlerTable keyed (this node's id, event).
    /** @var array<int, array{0: string, 1: callable|string}> */
    public array $bindings = [];

    /** @param array<string, mixed> $props */
    public function __construct(
        public string $type,
        public array $props = [],
        public ?string $id = null,
    ) {}

    public function id(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    /** @param array<int, Node> $children */
    public function content(array $children): static
    {
        $this->children = $children;

        return $this;
    }

    // THE binding registrar (D1/S2). Records a [event, handler] onto the dedicated,
    // non-serialized bindings field AND opens the event on the props.events round-trip
    // allowlist via withEvent() -- binding a handler and opening its wire round-trip are
    // ONE act, so a bound handler can never sit behind a closed allowlist. The handler is
    // a closure (inline style) or a method-name string (named style); BoundHandler
    // normalises both at dispatch. NEVER written into props -- the serializer drops the
    // bindings field for free.
    public function on(string $event, callable|string $handler): static
    {
        $this->bindings[] = [$event, $handler];

        return $this->withEvent($event);
    }

    // Idempotently add an event to the props.events round-trip allowlist (D4).
    // Shared by every stateful builder (TextField, Checkbox, future inputs) so the
    // append logic can't drift between them. Assumes props.events is already an
    // array -- the builder seeds it in its constructor.
    protected function withEvent(string $event): static
    {
        $events = $this->props['events'] ?? [];
        if (! in_array($event, $events, true)) {
            $events[] = $event;
        }
        $this->props['events'] = $events;

        return $this;
    }
}

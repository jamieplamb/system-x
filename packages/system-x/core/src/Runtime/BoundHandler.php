<?php

namespace SystemX\Core\Runtime;

use Closure;
use ReflectionFunction;

// Normalises BOTH binding styles (D1) to one Closure bound to the LIVE hydrated App,
// so $this inside the handler mutates the real persistent property. An inline closure
// is rebound to the App; a named method becomes a closure over that method. Dispatch
// is arity-aware: a zero-arg handler is called bare; a one-arg handler receives the
// WidgetEvent VO (NOT Livewire-style stringly-typed positional args).
class BoundHandler
{
    private function __construct(private Closure $handler) {}

    // App is referenced as a bare object here -- the framework App base is a later
    // task, so we keep no hard dependency on it (same posture as PropertyHydrator).
    public static function from(object $app, callable|string $handler): self
    {
        if (is_string($handler)) {
            // Named method -> a closure bound to the instance. Closure::fromCallable
            // throws on a missing or non-public method, so method-name handlers can
            // only ever resolve to a PUBLIC method on the live app.
            return new self(Closure::fromCallable([$app, $handler]));
        }

        // Inline closure -> rebind to the live instance so $this resolves to the App.
        $closure = Closure::fromCallable($handler);

        return new self(Closure::bind($closure, $app, $app::class));
    }

    public function __invoke(WidgetEvent $event): mixed
    {
        // Arity 0 -> call bare; arity >= 1 -> pass the event VO. Closures that
        // genuinely take no parameter must not be handed an argument they ignore-by-
        // accident, so we branch on the reflected parameter count.
        $arity = (new ReflectionFunction($this->handler))->getNumberOfParameters();

        return $arity === 0 ? ($this->handler)() : ($this->handler)($event);
    }
}

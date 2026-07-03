<?php

namespace SystemX\Core\Wire;

use InvalidArgumentException;

// The PHP half of the extension seam. Maps a wire type-name to its builder class.
// The Serializer is already type-agnostic, so this registry does NOT gate
// serialization -- its job is to make the seam symmetric and enumerable for the
// PHP<->JS pairing contract test. A pro pack calls register('vendor.gauge', Gauge::class).
class WidgetRegistry
{
    /** @var array<string, class-string> */
    private array $builders = [];

    public function register(string $type, string $builderClass): void
    {
        if (isset($this->builders[$type])) {
            throw new InvalidArgumentException("system-x: widget type \"{$type}\" is already registered -- two providers registering the same type collide. Namespace third-party types as vendor.name.");
        }

        $this->builders[$type] = $builderClass;
    }

    public function has(string $type): bool
    {
        return isset($this->builders[$type]);
    }

    public function builderFor(string $type): string
    {
        if (! isset($this->builders[$type])) {
            throw new InvalidArgumentException("system-x: no builder registered for type \"{$type}\".");
        }

        return $this->builders[$type];
    }

    /** @return array<int, string> */
    public function types(): array
    {
        return array_keys($this->builders);
    }
}

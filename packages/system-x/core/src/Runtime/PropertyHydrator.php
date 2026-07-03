<?php

namespace SystemX\Core\Runtime;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use SystemX\Core\State\StateBag;

// Maps an App subclass's public typed properties <-> a 4a StateBag (D2). This is the
// WHOLE of the App<->bag mapping the StateStore deliberately does not know about
// (D3) -- the store stays a dumb key->bag map; this is where declared properties
// become bag entries. v1 persists scalars + flat arrays only; object/enum-typed
// properties are ignored (the future "synth" path handles those).
class PropertyHydrator
{
    // The framework App base, referenced by name so this class has no hard
    // dependency on it (Task 4 introduces it). Properties declared on the base --
    // injected services and the like -- are NEVER persistent.
    private const APP_BASE = 'SystemX\\Core\\Runtime\\App';

    public function hydrate(object $app, StateBag $bag): void
    {
        $data = $bag->toArray();

        foreach ($this->persistentProperties($app) as $property) {
            $name = $property->getName();

            // Absent key -> keep the declared default (or leave uninitialised).
            if (! array_key_exists($name, $data)) {
                continue;
            }

            $property->setValue($app, $this->coerce($property, $data[$name]));
        }
    }

    /** @return array<string, mixed> */
    public function dehydrate(object $app): array
    {
        $out = [];

        foreach ($this->persistentProperties($app) as $property) {
            // A typed-but-uninitialised property (no default, no incoming key) is
            // skipped -- it never lands in the bag as null.
            if (! $property->isInitialized($app)) {
                continue;
            }

            $out[$property->getName()] = $property->getValue($app);
        }

        return $out;
    }

    /** @return array<int, ReflectionProperty> */
    private function persistentProperties(object $app): array
    {
        $reflection = new ReflectionClass($app);

        return array_values(array_filter(
            $reflection->getProperties(ReflectionProperty::IS_PUBLIC),
            fn (ReflectionProperty $p): bool => $this->isPersistent($p),
        ));
    }

    // THE single persistence gate (D2). Public is guaranteed by the caller's filter;
    // here we enforce non-static, non-readonly, declared SCALAR-or-ARRAY type, no
    // #[Transient], and -- the structural safety property -- a declaring class that
    // is NOT the framework App base. An injected service (readonly/typed-as-a-class)
    // cannot pass this, so it can never reach the durable bag.
    private function isPersistent(ReflectionProperty $property): bool
    {
        if ($property->isStatic() || $property->isReadOnly()) {
            return false;
        }

        if ($property->getAttributes(Transient::class) !== []) {
            return false;
        }

        $type = $property->getType();
        if (! $type instanceof ReflectionNamedType) {
            return false; // no type, or a union/intersection -> not v1-persistent
        }

        if (! $this->isScalarOrArrayType($type->getName())) {
            return false; // object/enum types are ignored in v1
        }

        // The declaring class must be the App SUBCLASS, never the framework base.
        // (App::class is checked by name so this class has no hard dependency on it.)
        return $property->getDeclaringClass()->getName() !== self::APP_BASE;
    }

    private function isScalarOrArrayType(string $type): bool
    {
        return in_array($type, ['int', 'float', 'string', 'bool', 'array'], true);
    }

    private function coerce(ReflectionProperty $property, mixed $value): mixed
    {
        $type = $property->getType();
        if (! $type instanceof ReflectionNamedType) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => (int) $value,
            'float' => (float) $value,
            'string' => (string) $value,
            'bool' => (bool) $value,
            'array' => (array) $value,
            default => $value,
        };
    }
}

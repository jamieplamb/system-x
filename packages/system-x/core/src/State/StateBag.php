<?php

namespace SystemX\Core\State;

// Immutable-with state bag: raw array data + a format version. with() returns a
// NEW bag so callers never alias (4b's declared-property hydration is a pure map).
// NOTHING about apps/handlers/dispatch lives here -- 4b generalises WHAT goes in
// the bag, not the bag's API.
class StateBag
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly array $data,
        public readonly int $version,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function with(string $key, mixed $value): self
    {
        $data = $this->data;
        $data[$key] = $value;

        return new self($data, $this->version);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}

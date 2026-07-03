<?php

namespace SystemX\Core\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SystemX\Core\Runtime\PropertyHydrator;
use SystemX\Core\Runtime\Transient;
use SystemX\Core\State\StateBag;
use SystemX\Core\Wire\Node;
use SystemX\Core\Wire\Serializer;

// A throwaway App-like fixture. It does NOT extend the framework App (Task 4) -- the
// hydrator only needs an object with declared properties to prove the gate. The
// declaring-class gate (subclass-only) is exercised against the real App base in
// Task 4's lifecycle test; here we prove the property-shape gates in isolation.
class HydratorFixture
{
    public int $count = 0;                 // persistent: public, typed, has default

    public string $note = 'hi';            // persistent

    public array $tags = [];               // persistent: flat array

    #[Transient]
    public int $ephemeral = 7;             // excluded by attribute

    public ?Node $widget = null;           // object-typed -> ignored (v1 scalars/arrays)

    public int $uninitialised;             // typed, no default, no bag key -> stays unset

    protected int $hidden = 1;             // non-public -> excluded

    public static int $shared = 0;         // static -> excluded

    public function __construct(public readonly Serializer $service = new Serializer) {}
    // readonly + a service: the dangerous case -- must NEVER be persisted.
}

class PropertyHydratorTest extends TestCase
{
    private function hydrator(): PropertyHydrator
    {
        return new PropertyHydrator;
    }

    public function test_it_hydrates_a_scalar_from_the_bag(): void
    {
        $fixture = new HydratorFixture;
        $this->hydrator()->hydrate($fixture, new StateBag(['count' => 4], 1));

        $this->assertSame(4, $fixture->count);
    }

    public function test_an_absent_key_keeps_the_declared_default(): void
    {
        $fixture = new HydratorFixture;
        $this->hydrator()->hydrate($fixture, new StateBag([], 1));

        $this->assertSame(0, $fixture->count);    // default, not null
        $this->assertSame('hi', $fixture->note);
    }

    public function test_a_present_key_coerces_to_the_declared_scalar_type(): void
    {
        $fixture = new HydratorFixture;
        // The bag came back as a string (JSON round-trip noise); coerce to int.
        $this->hydrator()->hydrate($fixture, new StateBag(['count' => '9'], 1));

        $this->assertSame(9, $fixture->count);
    }

    public function test_dehydrate_round_trips_the_persistent_scalars_and_arrays(): void
    {
        $fixture = new HydratorFixture;
        $fixture->count = 3;
        $fixture->note = 'bye';
        $fixture->tags = ['a', 'b'];

        $out = $this->hydrator()->dehydrate($fixture);

        $this->assertSame(['count' => 3, 'note' => 'bye', 'tags' => ['a', 'b']], $out);
    }

    public function test_transient_properties_are_never_persisted(): void
    {
        $fixture = new HydratorFixture;
        $fixture->ephemeral = 99;

        $this->assertArrayNotHasKey('ephemeral', $this->hydrator()->dehydrate($fixture));
    }

    public function test_object_typed_properties_are_ignored_in_v1(): void
    {
        $fixture = new HydratorFixture;

        $this->assertArrayNotHasKey('widget', $this->hydrator()->dehydrate($fixture));
        // And hydrate ignores an object-typed key in the bag rather than coercing.
        $this->hydrator()->hydrate($fixture, new StateBag(['widget' => ['type' => 'x']], 1));
        $this->assertNull($fixture->widget);
    }

    public function test_an_injected_service_can_never_be_persisted(): void
    {
        // THE SAFETY PROPERTY (D2): a readonly, constructor-promoted service is
        // structurally excluded -- readonly fails the gate, and even were it not
        // readonly, the object type fails the scalar/array coercion.
        $fixture = new HydratorFixture;

        $this->assertArrayNotHasKey('service', $this->hydrator()->dehydrate($fixture));
    }

    public function test_a_typed_uninitialised_property_is_skipped_on_dehydrate(): void
    {
        $fixture = new HydratorFixture; // $uninitialised never set

        $this->assertArrayNotHasKey('uninitialised', $this->hydrator()->dehydrate($fixture));
    }

    public function test_non_public_and_static_properties_are_excluded(): void
    {
        $out = $this->hydrator()->dehydrate(new HydratorFixture);

        $this->assertArrayNotHasKey('hidden', $out);
        $this->assertArrayNotHasKey('shared', $out);
    }
}

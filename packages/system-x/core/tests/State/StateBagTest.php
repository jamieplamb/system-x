<?php

namespace SystemX\Core\Tests\State;

use PHPUnit\Framework\TestCase;
use SystemX\Core\State\StateBag;

class StateBagTest extends TestCase
{
    public function test_get_returns_a_default_on_a_missing_key(): void
    {
        $bag = new StateBag([], 1);

        $this->assertSame(0, $bag->get('count', 0));
        $this->assertNull($bag->get('missing'));
    }

    public function test_get_returns_a_stored_value(): void
    {
        $bag = new StateBag(['count' => 7], 1);

        $this->assertSame(7, $bag->get('count', 0));
    }

    public function test_with_returns_a_new_bag_and_does_not_mutate_the_original(): void
    {
        $original = new StateBag(['count' => 1], 1);

        $next = $original->with('count', 2);

        $this->assertNotSame($original, $next);        // a new instance
        $this->assertSame(1, $original->get('count')); // original untouched
        $this->assertSame(2, $next->get('count'));
        $this->assertSame(1, $next->version);          // version rides along unchanged
    }

    public function test_to_array_exposes_the_raw_data(): void
    {
        $bag = new StateBag(['count' => 3, 'note' => 'hi'], 1);

        $this->assertSame(['count' => 3, 'note' => 'hi'], $bag->toArray());
    }
}

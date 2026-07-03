<?php

namespace SystemX\Core\Tests\State;

use PHPUnit\Framework\TestCase;
use SystemX\Core\State\StateKey;

class StateKeyTest extends TestCase
{
    public function test_it_holds_the_three_string_components(): void
    {
        $key = new StateKey('desktop', 'abc-123', 'win-1');

        $this->assertSame('desktop', $key->principalType);
        $this->assertSame('abc-123', $key->principalId);
        $this->assertSame('win-1', $key->windowId);
    }
}

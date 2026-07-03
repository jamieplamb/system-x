<?php

namespace SystemX\Core\Tests\Audit;

use PHPUnit\Framework\TestCase;
use SystemX\Core\Audit\BagDiff;

class BagDiffTest extends TestCase
{
    public function test_unchanged_properties_produce_no_delta(): void
    {
        $this->assertSame([], BagDiff::between(['count' => 1], ['count' => 1]));
    }

    public function test_scalar_change_reports_old_and_new(): void
    {
        $this->assertSame(['count' => [0, 1]], BagDiff::between(['count' => 0], ['count' => 1]));
    }

    public function test_first_save_reports_default_to_value_not_null(): void
    {
        // Snapshot A is the post-hydrate property set (defaults present), NOT an absent row -- so
        // a fresh window's first interaction reports 0 -> 1 (default changed) and only reports a
        // genuinely new key (label) as null -> value. A property left at its default (notify) is
        // byte-equal in A and B and never appears.
        $this->assertSame(
            ['count' => [0, 1], 'label' => [null, 'hi']],
            BagDiff::between(['count' => 0, 'notify' => false], ['count' => 1, 'notify' => false, 'label' => 'hi']),
        );
    }

    public function test_array_value_change_is_detected(): void
    {
        $this->assertSame(
            ['tags' => [['a'], ['a', 'b']]],
            BagDiff::between(['tags' => ['a']], ['tags' => ['a', 'b']]),
        );
    }

    public function test_added_and_removed_keys(): void
    {
        $this->assertSame(['notify' => [null, true]], BagDiff::between([], ['notify' => true]));
        $this->assertSame(['notify' => [true, null]], BagDiff::between(['notify' => true], []));
    }
}

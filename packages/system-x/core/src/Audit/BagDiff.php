<?php

namespace SystemX\Core\Audit;

// The change record's engine (audit plan §6). Diffs two DEHYDRATED property snapshots (the
// app's full property set before vs after dispatch, captured by AppKernel), NOT stored bags --
// so a first-ever interaction reports default->value (0 -> 1), never null -> 1. JSON-compares
// per key so arrays compare by value; a missing key is null on that side.
class BagDiff
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function between(array $old, array $new): array
    {
        $delta = [];

        foreach (array_keys($old + $new) as $key) {
            $before = $old[$key] ?? null;
            $after = $new[$key] ?? null;

            if (json_encode($before) !== json_encode($after)) {
                $delta[$key] = [$before, $after];
            }
        }

        return $delta;
    }
}

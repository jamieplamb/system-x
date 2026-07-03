<?php

namespace SystemX\Core\Runtime;

// The kernel's event result (audit plan §5.1). handle() returns the serialized tree AND the
// property delta (BagDiff of the app's dehydrated properties before vs after dispatch) so the
// controller records the change rows without the kernel knowing about audit. renderInitial()/
// renderFromBag() (no dispatch) are unchanged and still return a bare tree array.
class HandleResult
{
    /**
     * @param  array<string, mixed>  $tree
     * @param  array<string, array{0: mixed, 1: mixed}>  $delta
     */
    public function __construct(
        public readonly array $tree,
        public readonly array $delta,
    ) {}
}

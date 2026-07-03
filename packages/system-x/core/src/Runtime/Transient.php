<?php

namespace SystemX\Core\Runtime;

use Attribute;

// Marks a public typed property as NON-persistent (D2). A property carrying this
// attribute is skipped by both hydrate() and dehydrate() -- use it for derived/
// per-request state that must NOT survive into the durable bag.
#[Attribute(Attribute::TARGET_PROPERTY)]
class Transient {}

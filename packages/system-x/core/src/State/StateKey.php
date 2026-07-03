<?php

namespace SystemX\Core\State;

// The bag key, the spec's (user, window) key with `user` split into
// (principalType, principalId) so the placeholder desktop principal and the real
// Plan 4c user are the SAME shape. 4c re-keys by changing the resolver's output
// values ('user', (string) $user->id), not the schema -- principal_id is a string
// column so a uuid OR an int user id both fit with zero migration.
class StateKey
{
    public function __construct(
        public readonly string $principalType,
        public readonly string $principalId,
        public readonly string $windowId,
    ) {}
}

<?php

namespace SystemX\Core\Audit;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

// The per-interaction correlation context (audit plan §2). Immutable; minted at a chokepoint.
// The correlation_id ties an activity row to its change rows. windowId is nullable for lifecycle.
class AuditContext
{
    public function __construct(
        public readonly string $correlationId,
        public readonly string $principalType,
        public readonly string $principalId,
        public readonly string $app,
        public readonly ?string $windowId = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {}

    // The chokepoint factory: mint a fresh correlation id and pull the actor + provenance off the
    // authed request. The principal is the authenticated user (the only principal type today). One
    // home for the ULID + ip + user-agent plumbing so the audited endpoints don't each rebuild it.
    public static function forRequest(Request $request, string $app, ?string $windowId = null): self
    {
        return new self(
            (string) Str::ulid(),
            'user',
            (string) $request->user()->id,
            $app,
            $windowId,
            $request->ip(),
            (string) $request->userAgent(),
        );
    }
}

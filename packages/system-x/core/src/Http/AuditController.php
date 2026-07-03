<?php

namespace SystemX\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use SystemX\Core\Audit\AuditActivity;
use SystemX\Core\Audit\AuditChange;
use SystemX\Core\Http\Resources\AuditActivityResource;

// The audit viewer endpoint (audit plan §7). VIEWER-IMPLICIT: scoped to auth()->id() with no
// user parameter. Fetches the recent activity window, then BULK-loads the change rows by
// correlation_id IN (...) -- one query, no N+1 -- and stitches them on as a plain attribute.
class AuditController
{
    private const LIMIT = 50;

    public function index(Request $request): AnonymousResourceCollection
    {
        $userId = (string) $request->user()->id;

        $activity = AuditActivity::query()
            ->where('principal_type', 'user')
            ->where('principal_id', $userId)
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $changes = AuditChange::query()
            ->whereIn('correlation_id', $activity->pluck('correlation_id')->all())
            ->get()
            ->groupBy('correlation_id');

        $activity->each(fn (AuditActivity $row) => $row->setAttribute(
            'changes', $changes->get($row->correlation_id, collect())->values(),
        ));

        return AuditActivityResource::collection($activity);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Live-demo liveness tracking (showcase plan). Stamps last_active_at on the authed demo user so
// the prune measures genuine idleness, not "minted N minutes ago". Guards keep it a true no-op
// unless demo mode is on and the current user is an is_demo account; the write is throttled to
// only-if-older-than-60s so an active session doesn't write on every event POST. Liveness is HTTP
// only -- a purely WS-idle session isn't counted (accepted; the idle threshold covers it).
class TouchDemoActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('system-x-demo.enabled')) {
            return $response;
        }

        $user = $request->user();
        if ($user === null || ! $user->is_demo) {
            return $response;
        }

        if ($user->last_active_at === null || $user->last_active_at->lt(now()->subSeconds(60))) {
            $user->forceFill(['last_active_at' => now()])->save();
        }

        return $response;
    }
}

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Abandoned-window state GC (Plan 4a, D5). Daily is plenty -- the 24h TTL is
// far longer than any session, so there's no urgency to sweep more often.
Schedule::command('system-x:prune-state')->daily();

// Live-demo GC (showcase plan). Every 15 min drains idle demo users so the cap stays a real
// ceiling. Scheduled ONLY when demo mode is on -- a normal install never runs it.
if (config('system-x-demo.enabled')) {
    Schedule::command('system-x:prune-demo-users')->everyFifteenMinutes();
}

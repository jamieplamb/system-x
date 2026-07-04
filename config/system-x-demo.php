<?php

// Live-demo mode for the reference host (showcase plan). OFF by default: with the
// flag off, none of the demo surface exists and the host behaves exactly as a normal
// install. All three keys are env-backed so Laravel Cloud tunes them without a redeploy.
return [
    // Master switch. Off => /demo/launch 404s, /login is the normal greeter, no prune,
    // the activity middleware no-ops, the welcome app is not registered.
    'enabled' => (bool) env('SYSTEM_X_DEMO_MODE', false),

    // Soft ceiling on concurrent ephemeral users. At/over this, /demo/launch renders the
    // capacity page and mints nothing. Bounds DB + Reverb blast radius.
    'max_users' => (int) env('SYSTEM_X_DEMO_MAX_USERS', 500),

    // Idle threshold: a demo user whose last_active_at is older than this is pruned.
    'idle_minutes' => (int) env('SYSTEM_X_DEMO_IDLE_MINUTES', 30),
];

<?php

use Illuminate\Support\Facades\Broadcast;

// Authorize the user channel against the REAL authenticated user (Plan 5c, D6). The
// channel is user.{id} -- the principal IS the user, so the channel reads as such; {id}
// is the authenticated user id. The id-match stops user A subscribing to user B's
// channel. The `['guards' => ['desktop']]` option is GONE -- the default `web`
// session guard authenticates /broadcasting/auth now (the Auth::viaRequest('desktop')
// shim is removed from the provider).
Broadcast::channel('user.{id}', function ($user, string $id): bool {
    return (int) $user->getAuthIdentifier() === (int) $id;
});

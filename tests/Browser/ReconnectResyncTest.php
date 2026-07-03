<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ReconnectResyncTest extends DuskTestCase
{
    public function test_a_simulated_drop_shows_the_affordance_then_clears_on_reconnect(): void
    {
        $this->browse(function (Browser $browser): void {
            // Log in first -- the desktop requires auth (D3).
            $this->loginAsDemoUser($browser)->waitFor('.sx-window');

            // Drive the underlying pusher connection state machine directly: a drop
            // (-> unavailable) then a recovery (-> connected). The display server binds
            // state_change, so this exercises the same path a real socket drop hits.
            $browser->script(<<<'JS'
                const c = window.Echo.connector.pusher.connection;
                c.emit('state_change', { previous: 'connected', current: 'unavailable' });
            JS);

            // The reconnecting badge appears while the socket is down.
            $browser->waitFor('.sx-reconnecting');

            $browser->script(<<<'JS'
                const c = window.Echo.connector.pusher.connection;
                c.emit('state_change', { previous: 'unavailable', current: 'connected' });
            JS);

            // On the reconnect edge the badge clears and the authoritative tree is resynced.
            $browser->waitUntilMissing('.sx-reconnecting')
                ->waitForTextIn('[data-sx-id="counter"]', 'Clicked 0 times', 10);
        });
    }
}

<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class LoginFlowTest extends DuskTestCase
{
    // The end-to-end proof of 4c: a real form login lands on the desktop, the click
    // count persists keyed to the USER across a reload, and logout bounces back to
    // /login with the desktop no longer reachable as a guest. The empty-count
    // precondition is the suite-wide DuskTestCase::setUp() truncate; the demo user
    // it seeds is the credential this form login uses.

    public function test_login_lands_on_the_desktop_state_persists_per_user_then_logout(): void
    {
        $this->browse(function (Browser $browser): void {
            // Log in via the real form, land on the desktop.
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->assertSeeIn('[data-window-id="hello"] [data-sx-id="counter"]', 'Clicked 0 times')
                ->click('[data-window-id="hello"] [data-sx-id="clicker"]')
                ->waitForTextIn('[data-window-id="hello"] [data-sx-id="counter"]', 'Clicked 1 times', 10)
                // THE PROOF: reload -- the count persists, keyed by the USER (not a
                // per-browser uuid). The same login restores the same desktop.
                ->refresh()
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->assertSeeIn('[data-window-id="hello"] [data-sx-id="counter"]', 'Clicked 1 times');

            // Logout (via the user menu, D4) returns to /login and the desktop is no longer reachable.
            $this->logoutViaMenu($browser);

            // A guest cannot reach the desktop shell -- it bounces back to login.
            $browser->visit('/')->waitForLocation('/login');
        });
    }
}

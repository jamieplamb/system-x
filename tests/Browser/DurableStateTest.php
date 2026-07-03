<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class DurableStateTest extends DuskTestCase
{
    // The empty-count precondition lives in DuskTestCase::setUp(): it truncates
    // system_x_window_states once before every browser test, making the clean-state
    // invariant suite-wide rather than something this method sets up. setUp() runs
    // before the method, NOT between the browse() calls below, so the across-refresh
    // persistence this test proves is untouched.

    public function test_the_click_count_survives_a_page_reload(): void
    {
        $this->browse(function (Browser $browser): void {
            // Log in first -- the desktop now requires auth (D3), so a guest hitting
            // '/' bounces to /login. The durable behaviour below is unchanged; it is
            // now keyed to the demo USER instead of an anonymous desktop uuid.
            $this->loginAsDemoUser($browser)
                ->waitFor('.sx-window')
                ->assertSeeIn('[data-sx-id="counter"]', 'Clicked 0 times')
                // Click to 2 -- each click round-trips over Reverb off the durable count.
                ->click('[data-sx-id="clicker"]')
                ->waitForTextIn('[data-sx-id="counter"]', 'Clicked 1 times', 10)
                ->click('[data-sx-id="clicker"]')
                ->waitForTextIn('[data-sx-id="counter"]', 'Clicked 2 times', 10)
                // THE PROOF: reload. Under the old echo hack this reset to 0. Now the
                // resync GET reads the persisted count, so it is STILL 2.
                ->refresh()
                ->waitFor('.sx-window')
                ->assertSeeIn('[data-sx-id="counter"]', 'Clicked 2 times');
        });
    }
}

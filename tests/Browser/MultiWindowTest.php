<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class MultiWindowTest extends DuskTestCase
{
    // The empty-state precondition is the suite-wide DuskTestCase::setUp() truncate
    // (4a Task 10) -- it clears system_x_window_states before each browser test, so
    // both windows start fresh.

    public function test_both_windows_render_and_are_independently_durable(): void
    {
        $this->browse(function (Browser $browser): void {
            // Log in first -- the desktop requires auth (D3); both windows are now keyed
            // to the demo USER instead of an anonymous desktop uuid.
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="notes"] .sx-window')
                // HelloApp's single-window assertions still hold, scoped to its surface.
                ->assertSeeIn('[data-window-id="hello"] [data-sx-id="counter"]', 'Clicked 0 times')
                ->click('[data-window-id="hello"] [data-sx-id="clicker"]')
                ->waitForTextIn('[data-window-id="hello"] [data-sx-id="counter"]', 'Clicked 1 times', 10)
                // The notes window is independent -- typing + submit mutates ONLY notes.
                ->type('[data-window-id="notes"] [data-sx-id="message-field"] input', 'buy milk')
                ->keys('[data-window-id="notes"] [data-sx-id="message-field"] input', '{enter}')
                ->waitForTextIn('[data-window-id="notes"] [data-sx-id="preview"]', 'buy milk', 10)
                // THE PROOF: reload -- each window's durable state survives independently.
                ->refresh()
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->assertSeeIn('[data-window-id="hello"] [data-sx-id="counter"]', 'Clicked 1 times')
                ->assertSeeIn('[data-window-id="notes"] [data-sx-id="preview"]', 'buy milk');
        });
    }
}

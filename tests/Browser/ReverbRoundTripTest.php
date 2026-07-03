<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ReverbRoundTripTest extends DuskTestCase
{
    public function test_a_click_round_trips_and_renders_via_the_websocket(): void
    {
        $this->browse(function (Browser $browser): void {
            // Log in first -- the desktop requires auth (D3); the browser's private
            // channel is now private-user.{userId}.
            $this->loginAsDemoUser($browser)
                ->waitFor('.sx-window')
                ->assertSee('Hello')
                ->assertSeeIn('[data-sx-id="counter"]', 'Clicked 0 times')
                // The label only updates if the broadcast arrived over Reverb and rendered.
                ->click('[data-sx-id="clicker"]')
                ->waitForTextIn('[data-sx-id="counter"]', 'Clicked 1 times', 10)
                ->click('[data-sx-id="clicker"]')
                ->waitForTextIn('[data-sx-id="counter"]', 'Clicked 2 times', 10);
        });
    }

    public function test_an_unsolicited_push_re_renders_an_open_desktop(): void
    {
        $this->browse(function (Browser $browser): void {
            // Log in first -- the desktop requires auth (D3).
            $this->loginAsDemoUser($browser)->waitFor('.sx-window');

            // HARD RULE (S5): the channel/push id is resolved DYNAMICALLY off the live
            // page -- data-desktop-id is now the demo USER's id, whatever the DB assigned.
            // NEVER hardcode it: pushing to a wrong id targets a channel nobody is
            // subscribed to, and waitForChannel below would hang until timeout.
            $desktopId = $browser->attribute('#sx-desktop', 'data-desktop-id');

            // boot() subscribes to the private channel AFTER the initial fetch, and
            // Reverb does not replay frames missed before a subscription lands -- so
            // block until the channel is live before pushing, or the pushed frame can
            // race ahead of the listener and be dropped. (This previously passed by
            // luck without the barrier; sharing it removes a latent flake.)
            $this->waitForChannel($browser, $desktopId);
            $this->artisan('system-x:push', ['desktopId' => $desktopId, 'count' => 77]);

            $browser->waitForTextIn('[data-sx-id="counter"]', 'Clicked 77 times', 10);
        });
    }
}

<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

// The headline proof: a THIRD-PARTY app (the example/todo-app package, auto-discovered, zero host
// code) launches from the launcher and round-trips a handler over the live websocket -- built from
// CORE widgets only. If this passes, a stranger can ship a working system-x app as a composer package.
class ThirdPartyAppTest extends DuskTestCase
{
    public function test_the_example_third_party_app_launches_and_round_trips(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)->waitFor('[data-window-id="hello"] .sx-window');

            // Open the example app via the launcher (openViaLauncher is private to another class --
            // inline the same 4-line pattern).
            $browser->click('[data-sx-launcher]')
                ->waitFor('[data-sx-launch="example.todo"]')
                ->click('[data-sx-launch="example.todo"]')
                ->waitUntilMissing('[data-sx-launch]', 10);

            // The third-party window is live.
            $browser->waitFor('[data-app="example.todo"] .sx-window')
                ->waitFor('[data-app="example.todo"] [data-sx-id="draft"]');

            // Round-trip a handler: commit a task into the durable $draft, click Add, assert it
            // renders. The draft field commits on onChange (the native change event), and add()
            // reads $this->draft -- so the value must have round-tripped BEFORE the click. Drive
            // the inner input the gallery's way: set .value + dispatch a bubbling `change` (the
            // dispatcher is a delegated surface listener, so the event must bubble), wait for the
            // draft to land, THEN click Add. The item re-renders over the WS as "[ ] buy milk".
            $browser->script(<<<'JS'
                const input = document.querySelector('[data-app="example.todo"] [data-sx-id="draft"] input');
                input.value = 'buy milk';
                input.dispatchEvent(new Event('change', { bubbles: true }));
            JS);

            // Let the draft round-trip commit server-side before firing Add (both are async POSTs;
            // add() reads the durable $draft, so it must have landed first).
            $browser->pause(400)
                ->click('[data-app="example.todo"] [data-sx-id="add"]')
                ->waitForText('buy milk');
        });
    }
}

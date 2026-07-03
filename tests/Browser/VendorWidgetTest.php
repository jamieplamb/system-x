<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

// The vendor-asset proof: a THIRD-PARTY custom widget (example.gauge, shipped by the example/todo-app
// package with its own JS renderer + CSS) renders STYLED -- not the unknown placeholder -- because its
// vendor bundle registered into window.SystemX.renderers before first paint. Its distinctive pill
// radius proves the vendor CSS loaded, and a click round-trips through the delegated dispatcher to the
// bound handler. If this passes, a stranger can ship a NEW widget type (renderer + CSS) as a package.
class VendorWidgetTest extends DuskTestCase
{
    public function test_a_third_party_custom_widget_renders_styled_and_round_trips(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)->waitFor('[data-window-id="hello"] .sx-window');

            // Open the example app via the launcher (same inline 4-line pattern as ThirdPartyAppTest).
            $browser->click('[data-sx-launcher]')
                ->waitFor('[data-sx-launch="example.todo"]')
                ->click('[data-sx-launch="example.todo"]')
                ->waitUntilMissing('[data-sx-launch]', 10);

            // The third-party window is live, and its custom widget renders as the styled gauge --
            // a <button class="sx-example-gauge"> with data-sx-type stamped centrally by the registry,
            // showing the seeded value 0. If the vendor JS hadn't registered, we'd get the unknown
            // placeholder instead.
            $browser->waitFor('[data-app="example.todo"] .sx-window')
                ->waitFor('[data-sx-type="example.gauge"]')
                ->assertPresent('.sx-example-gauge')
                ->assertSeeIn('[data-sx-type="example.gauge"]', '0');

            // The vendor CSS half loaded -- the distinctive pill radius is applied. Use a substring
            // match (getComputedStyle can return a longhand form).
            $radius = $browser->script(
                "return getComputedStyle(document.querySelector('.sx-example-gauge')).borderRadius;"
            )[0];
            $this->assertStringContainsString('999px', $radius);

            // Click round-trips through the delegated dispatcher to the bound handler (count++). This
            // broadcasts a re-render over Reverb, same as a core Button -- give it the same beat the
            // draft round-trip gets in ThirdPartyAppTest before asserting the new value.
            $browser->click('[data-sx-type="example.gauge"]')
                ->pause(400)
                ->waitForTextIn('[data-sx-type="example.gauge"]', '1')
                ->assertSeeIn('[data-sx-type="example.gauge"]', '1');
        });
    }
}

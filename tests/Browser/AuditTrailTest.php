<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

// End-to-end proof for the audit pipeline (audit plan Task 11). Drives a real interaction
// (a clicker click on the Hello app), opens the Audit system app from the user menu, and
// asserts the trail viewer shows a row for that interaction -- proves the full path from
// kernel chokepoint -> activity write -> GET /system-x/audit -> audit.js paint.
class AuditTrailTest extends DuskTestCase
{
    // Open the user-menu dropdown robustly -- mirrors AppInstallTest's pattern exactly. The
    // panel wires the click handler during boot; a click fired the instant the desktop paints
    // can be swallowed by headless Chrome. Wait for the JS handle, give the panel a beat, then
    // click-and-confirm via the isOpen() flag with a retry loop.
    private function openUserMenu(Browser $browser): void
    {
        $browser->waitUntil('window.sx && window.sx.systemMenu', 10)
            ->pause(250);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            if ($browser->script('return window.sx.systemMenu.isOpen();')[0]) {
                $browser->waitFor('.sx-system-menu');

                return;
            }

            $browser->click('.sx-panel-user');

            try {
                $browser->waitUsing(2, 100, fn () => $browser->script('return window.sx.systemMenu.isOpen();')[0]);
                $browser->waitFor('.sx-system-menu');

                return;
            } catch (\Exception $e) {
                // Lost click -- ensure the menu is closed, settle, then retry.
                $browser->script('if (window.sx.systemMenu.isOpen()) { window.sx.systemMenu.close(); }');
                $browser->pause(250);
            }
        }

        $browser->waitFor('.sx-system-menu');
    }

    public function test_clicking_hello_produces_an_audit_row_visible_in_the_audit_app(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="notes"] .sx-window');

            // Click the Hello clicker -- this fires a widget event through the kernel chokepoint,
            // which stamps a correlation_id and writes an ACTIVITY row (+ a CHANGE row for the
            // click count increment). Give the round-trip a moment to land before opening the viewer.
            $browser->click('[data-window-id="hello"] [data-sx-id="clicker"]')
                ->waitForTextIn('[data-window-id="hello"] [data-sx-id="counter"]', 'Clicked 1 times', 15);

            // Open the Audit system app from the user menu.
            $this->openUserMenu($browser);

            $browser->assertPresent('.sx-system-menu [data-sx-menu="audit"]')
                ->click('.sx-system-menu [data-sx-menu="audit"]')
                ->waitFor('[data-app="audit"] .sx-window');

            // Wait for audit.js to fetch + paint -- the mount flips from "Loading audit trail..."
            // to actual [data-sx-audit-row] elements once the fetch resolves.
            $browser->waitFor('[data-app="audit"] [data-sx-audit-row]', 10);

            // The row head text is "{time} · {app} · {event} · {outcome}" -- assert the audit
            // mount contains "hello" somewhere in the painted rows (the click round-trip recorded
            // an activity row with app=hello). assertSeeIn scopes to the whole mount, not the
            // first row, so it finds "hello" wherever it lands in the list.
            $browser->assertSeeIn('[data-app="audit"] [data-sx-audit]', 'hello')
                ->screenshot('audit-trail-hello-row');
        });
    }
}

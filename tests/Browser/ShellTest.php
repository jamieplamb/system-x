<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ShellTest extends DuskTestCase
{
    // The TOP panel (Plan 5b, Task 4) is the visible centrepiece of the shell: a framework-
    // owned bar mounted on document.body (B4) that lists every OPEN window -- one button per
    // window, its title + icon joined from the boot metadata, the focused one pressed
    // (data-sx-active='true'). It re-renders live off the WM's notify seam. This proves the
    // panel paints in the real boot DOM with a button per seeded window (hello + notes), the
    // boot-focused one active, and captures a screenshot for eyeballing the chrome.
    public function test_the_top_panel_lists_each_open_window_with_the_focused_one_pressed(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="notes"] .sx-window')
                // The panel is body-mounted, fixed top, with a launcher button + tray.
                ->waitFor('.sx-panel')
                ->assertPresent('.sx-panel [data-sx-launcher]')
                ->assertPresent('.sx-panel [data-sx-clock]')
                // One running-window button per open window, labelled from the metadata join.
                ->assertPresent('.sx-panel [data-sx-panel-window="hello"]')
                ->assertPresent('.sx-panel [data-sx-panel-window="notes"]')
                ->assertSeeIn('.sx-panel [data-sx-panel-window="hello"]', 'Hello')
                ->assertSeeIn('.sx-panel [data-sx-panel-window="notes"]', 'Notes')
                // The WM boot-focuses the first window (hello), so its panel button is pressed
                // and notes' is not -- the panel mirrors the live WM focus.
                ->assertAttribute('.sx-panel [data-sx-panel-window="hello"]', 'data-sx-active', 'true')
                ->assertAttribute('.sx-panel [data-sx-panel-window="notes"]', 'data-sx-active', 'false')
                ->screenshot('shell-top-panel');

            // Clicking the notes panel button (inactive) focuses its window -- the panel
            // re-renders, notes' button goes pressed, hello's goes idle. The panel is in body,
            // outside the WM's mount-scoped listeners, so it wires its own click (bring/minimise).
            $browser->click('.sx-panel [data-sx-panel-window="notes"]')
                ->waitUsing(5, 100, fn () => $browser->attribute('.sx-panel [data-sx-panel-window="notes"]', 'data-sx-active') === 'true')
                ->assertAttribute('.sx-panel [data-sx-panel-window="notes"]', 'data-sx-active', 'true')
                ->assertAttribute('[data-window-id="notes"]', 'data-sx-active', 'true')
                ->assertAttribute('.sx-panel [data-sx-panel-window="hello"]', 'data-sx-active', 'false')
                ->screenshot('shell-top-panel-notes-focused');
        });
    }

    // The panel reflects CLOSE (Plan 5b, D3 + the DoD's "close removes one"): closing a window
    // via its close control drops its panel button. The WM reaps the surface + notifies; the
    // display server re-renders the panel off wm.windows(), which no longer carries the closed
    // window -- so its [data-sx-panel-window] button is gone. WindowManagerTest proves the close
    // POST/relaunch path; this proves the PANEL mirrors the close (the surviving button stays).
    public function test_closing_a_window_drops_its_panel_button(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="notes"] .sx-window')
                ->waitFor('.sx-panel [data-sx-panel-window="notes"]')
                ->waitFor('.sx-panel [data-sx-panel-window="hello"]')
                // Close notes via its control -- a real click (the logout overlap is gone, D6).
                ->click('[data-window-id="notes"] [data-sx-control="close"]')
                ->waitUntilMissing('[data-window-id="notes"]', 10)
                // The panel re-renders off the WM list: notes' button is gone, hello's survives.
                ->waitUntilMissing('.sx-panel [data-sx-panel-window="notes"]', 10)
                ->assertPresent('.sx-panel [data-sx-panel-window="hello"]');
        });
    }

    // Minimise-to-panel end-to-end (Plan 5b, D4 + Task 5): the WM minimise (Task 3) + the panel
    // button (Task 4) round-trip. Minimise hello via its control -> the surface hides
    // (data-sx-min='true', CSS display:none) + its panel button dims (data-sx-min='true') ->
    // click the panel button -> bring() un-minimises, raises + focuses. Client-only, no POST.
    public function test_minimise_to_panel_and_restore(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                // Minimise hello via its control -> the surface hides (data-sx-min on the surface,
                // CSS display:none -- it's still OPEN, just hidden to its panel button).
                ->click('[data-window-id="hello"] [data-sx-control="minimise"]')
                ->waitUsing(5, 100, fn () => $browser->attribute('[data-window-id="hello"]', 'data-sx-min') === 'true')
                ->assertAttribute('[data-window-id="hello"]', 'data-sx-min', 'true')
                // ...and its panel button dims.
                ->assertAttribute('.sx-panel [data-sx-panel-window="hello"]', 'data-sx-min', 'true')
                // Click the panel button -> it restores + focuses.
                ->click('.sx-panel [data-sx-panel-window="hello"]')
                ->waitUsing(5, 100, fn () => $browser->attribute('[data-window-id="hello"]', 'data-sx-min') === 'false')
                ->assertAttribute('[data-window-id="hello"]', 'data-sx-min', 'false')
                ->assertAttribute('[data-window-id="hello"]', 'data-sx-active', 'true');
        });
    }

    // Maximise insets BELOW the panel (Plan 5b, D7): a maximised window fills the work area
    // starting at y = PANEL_H (34px), never under the panel. A REAL Selenium click on the
    // maximise control (Task 7, D6 -- the floating-logout overlap is gone, so the control row
    // is reachable); Task 5 added the inset, Task 7 proves the real click on a maximised window.
    public function test_maximise_fills_below_the_panel(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window');

            $browser->click('[data-window-id="hello"] [data-sx-control="maximise"]');
            $browser->pause(150);

            $browser->assertAttribute('[data-window-id="hello"]', 'data-sx-max', 'true')
                // The maximised window starts BELOW the panel (its transform y == panel height),
                // so the panel stays visible above it.
                ->assertScript("document.querySelector('[data-window-id=\"hello\"]').style.transform.includes('34px')")
                ->screenshot('shell-maximise-below-panel');
        });
    }

    // Logout via the user menu (system-menu plan, D4): logout moved off the standalone tray
    // button INTO the user-menu dropdown. Open the menu, press the Log out item (it keeps
    // dusk="logout"), which POSTs /logout -> the session ends -> back to /login. Proves the
    // menu logout is a real POST and @logout still resolves once the dropdown is open.
    public function test_logout_from_the_user_menu_returns_to_login(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->assertPresent('.sx-panel-user');

            $this->logoutViaMenu($browser)
                ->assertPathIs('/login');
        });
    }
}

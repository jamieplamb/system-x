<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

// The end-to-end proof for app install/uninstall (App-install plan, Task 6). Open Manage apps from
// the user menu (it's a SYSTEM app, so it lives in the tray dropdown next to Appearance/About), then
// drive the whole round-trip in a real browser:
//   - UNINSTALL hello: the toggle flips to "Install"; the launcher tile is live-REMOVED; the open
//     hello window is closed on the spot.
//   - the launch GUARD: a forged POST /system-x/wm/launch {app:hello} 403s (an uninstalled non-system
//     app is not launchable -- the server enforces it even though the tile is gone client-side).
//   - PERSISTENCE: a reload keeps hello gone (the boot filter persisted the uninstall server-side).
//   - INSTALL hello back: the toggle flips to "Uninstall"; the tile returns + is launchable (click it
//     -> a hello window opens).
//   - the EMPTY state: uninstall BOTH user apps -> the launcher shows the empty-state hint, no tiles
//     (Manage apps is still reachable from the user menu, so the user is never stuck).
//
// Landmine (the toggle state is CLIENT-seeded, B1): Manage-apps render() has no principal, so the
// install/uninstall label + data-sx-installed is seeded on window-open from the launcher's live
// in-memory set -- so we wait for data-sx-installed='true' before asserting on it, not for the raw
// "Toggle" placeholder the App paints.
class AppInstallTest extends DuskTestCase
{
    // Open the user-menu dropdown robustly. The tray panel wires its click handler during boot, and
    // a click fired the instant the hello window paints can be lost (a known flake the SystemMenuTest
    // shares) -- so wait for the menu's JS handle to exist, then click-and-wait, retrying the click a
    // couple of times until the dropdown is actually on screen.
    private function openUserMenu(Browser $browser): void
    {
        // The panel wires the user button's click during boot; the JS handle existing is the proof
        // it's live. A click fired the instant the desktop paints can be dropped by headless Chrome
        // (the button is still being laid out), so wait for the handle, give the panel a beat to
        // settle, then click -- retrying on the menu's own isOpen() flag (the toggle's truth, not a
        // DOM node that can race the open). This preserves the real click path; it just doesn't race
        // boot. The SystemMenuTest shares this flake; here we make our own open deterministic.
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

    // Open the Manage-apps window from the user menu. Manage-apps is a SYSTEM app, so it's a dynamic
    // item in the tray dropdown (next to Appearance/About). Waits for the window AND for the client
    // seed to have run (hello's toggle reads its true installed state) before returning.
    private function openManageApps(Browser $browser): void
    {
        $this->openUserMenu($browser);

        $browser->assertPresent('.sx-system-menu [data-sx-menu="apps"]')
            ->click('.sx-system-menu [data-sx-menu="apps"]')
            ->waitFor('[data-app="apps"] .sx-window')
            // The client seed ran: hello's toggle reads its live installed state (D5/B1) -- wait for
            // it so we never assert against the App's raw "Toggle" placeholder.
            ->waitFor('[data-sx-app-action="hello"]');
    }

    // Close the launcher overlay via its exposed handle (window.sx is the live display server, app.js
    // line 10). Escape-via-Dusk-keys scopes the selector under <body> and resolves to a bogus
    // "body body" target, so we drive the same close() the Escape handler calls, then wait it gone.
    private function closeLauncher(Browser $browser): void
    {
        $browser->script('window.sx.launcher.close();');
        $browser->waitUntilMissing('.sx-launcher-grid');
    }

    public function test_uninstall_then_install_round_trip_with_the_launch_guard_and_empty_state(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window');

            // --- OPEN Manage apps (from the user menu) ------------------------------------------
            $this->openManageApps($browser);

            // Both user apps list a toggle; hello + notes seed as installed (a clean user).
            $browser->assertPresent('[data-sx-app-action="hello"]')
                ->assertPresent('[data-sx-app-action="notes"]')
                ->waitUsing(5, 100, fn () => $browser->attribute('[data-sx-app-action="hello"]', 'data-sx-installed') === 'true')
                ->assertSeeIn('[data-sx-app-action="hello"]', 'Uninstall')
                ->screenshot('app-install-manage-apps');

            // --- UNINSTALL hello -----------------------------------------------------------------
            // Click hello's toggle -> it flips to "Install" (data-sx-installed='false', a direct DOM
            // mutation, S4). The launcher tile is live-removed + the open hello window is closed.
            $browser->click('[data-sx-app-action="hello"]')
                ->waitUsing(5, 100, fn () => $browser->attribute('[data-sx-app-action="hello"]', 'data-sx-installed') === 'false')
                ->assertSeeIn('[data-sx-app-action="hello"]', 'Install')
                // The open hello window is gone on the spot (the surface was removed directly, B2 --
                // the boot pair's window-id IS its slug, so the surface is addressable by app here).
                ->waitUntilMissing('[data-app="hello"]');
            // And the surface is truly out of the WM -- a script count over the live surfaces, so we
            // never hang on a stale node mid-teardown.
            $browser->waitUsing(5, 100, fn () => $browser->script(
                "return document.querySelectorAll('.sx-window-surface[data-window-id=\"hello\"]').length === 0;"
            )[0]);

            // Open the LAUNCHER -> hello's tile is GONE (live-removed), notes is still there.
            $browser->click('[data-sx-launcher]')
                ->waitFor('.sx-launcher-grid')
                ->assertMissing('[data-sx-launch="hello"]')
                ->assertPresent('[data-sx-launch="notes"]');
            // Close the launcher so the next assertions aren't behind its backdrop.
            $this->closeLauncher($browser);

            // --- THE LAUNCH GUARD ----------------------------------------------------------------
            // A forged POST /system-x/wm/launch {app:hello} must 403 -- the tile is gone client-side,
            // but the server enforces the uninstall (an uninstalled non-system app is not launchable).
            // Drive a real fetch with the live CSRF token + read back the status (Dusk waits on the
            // promise resolving the status onto a known element).
            $browser->script(<<<'JS'
                window.__sxLaunchGuardStatus = 'pending';
                fetch('/system-x/wm/launch', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    },
                    body: JSON.stringify({ app: 'hello' }),
                }).then((res) => { window.__sxLaunchGuardStatus = String(res.status); })
                  .catch(() => { window.__sxLaunchGuardStatus = 'error'; });
            JS);
            $browser->waitUsing(5, 100, fn () => $browser->script('return window.__sxLaunchGuardStatus;')[0] !== 'pending');
            $status = $browser->script('return window.__sxLaunchGuardStatus;')[0];
            $this->assertSame('403', $status, 'A forged launch of an uninstalled app must 403 (the server guard).');

            // --- PERSISTENCE (a reload keeps hello gone) -----------------------------------------
            $browser->refresh()
                ->waitFor('[data-window-id="notes"] .sx-window')
                ->click('[data-sx-launcher]')
                ->waitFor('.sx-launcher-grid')
                ->assertMissing('[data-sx-launch="hello"]')
                ->assertPresent('[data-sx-launch="notes"]');
            $this->closeLauncher($browser);

            // --- INSTALL hello back --------------------------------------------------------------
            $this->openManageApps($browser);
            // hello seeds as uninstalled now (the reload persisted it) -- the toggle reads "Install".
            $browser->waitUsing(5, 100, fn () => $browser->attribute('[data-sx-app-action="hello"]', 'data-sx-installed') === 'false')
                ->assertSeeIn('[data-sx-app-action="hello"]', 'Install')
                // Click it -> back to "Uninstall" (installed), the tile returns.
                ->click('[data-sx-app-action="hello"]')
                ->waitUsing(5, 100, fn () => $browser->attribute('[data-sx-app-action="hello"]', 'data-sx-installed') === 'true')
                ->assertSeeIn('[data-sx-app-action="hello"]', 'Uninstall');

            // The tile is BACK + launchable: open the launcher, the hello tile is present, click it
            // -> a hello window opens (a real launch now succeeds again).
            $browser->click('[data-sx-launcher]')
                ->waitFor('.sx-launcher-grid')
                ->assertPresent('[data-sx-launch="hello"]')
                ->click('[data-sx-launch="hello"]')
                // A relaunch after uninstall mints a FRESH window (the boot slug-id row was deleted,
                // so firstOrCreate(app=hello) creates a ULID-id window) -- so address it by app, not
                // the old "hello" slug-id.
                ->waitFor('[data-app="hello"] .sx-window')
                ->assertPresent('[data-app="hello"]');

            // --- THE EMPTY STATE -----------------------------------------------------------------
            // Uninstall EVERY user app (hello + notes + controls + the third-party example.todo) ->
            // the launcher shows the empty-state hint, no tiles.
            $this->openManageApps($browser);
            $browser->waitUsing(5, 100, fn () => $browser->attribute('[data-sx-app-action="hello"]', 'data-sx-installed') === 'true');
            // Uninstall hello.
            $browser->click('[data-sx-app-action="hello"]')
                ->waitUsing(5, 100, fn () => $browser->attribute('[data-sx-app-action="hello"]', 'data-sx-installed') === 'false');
            // Uninstall notes.
            $browser->waitUsing(5, 100, fn () => $browser->attribute('[data-sx-app-action="notes"]', 'data-sx-installed') === 'true')
                ->click('[data-sx-app-action="notes"]')
                ->waitUsing(5, 100, fn () => $browser->attribute('[data-sx-app-action="notes"]', 'data-sx-installed') === 'false');
            // Uninstall controls (the widget-gallery demo is a user app too).
            $browser->waitUsing(5, 100, fn () => $browser->attribute('[data-sx-app-action="controls"]', 'data-sx-installed') === 'true')
                ->click('[data-sx-app-action="controls"]')
                ->waitUsing(5, 100, fn () => $browser->attribute('[data-sx-app-action="controls"]', 'data-sx-installed') === 'false');
            // Uninstall the third-party example.todo (a user app too, so it must go for an empty launcher).
            $browser->waitUsing(5, 100, fn () => $browser->attribute('[data-sx-app-action="example.todo"]', 'data-sx-installed') === 'true')
                ->click('[data-sx-app-action="example.todo"]')
                ->waitUsing(5, 100, fn () => $browser->attribute('[data-sx-app-action="example.todo"]', 'data-sx-installed') === 'false');
            // Uninstall the pro-datagrid demo (Cameras) -- a user app too, registered in dev/test/CI.
            $browser->waitUsing(5, 100, fn () => $browser->attribute('[data-sx-app-action="sxpro.demo"]', 'data-sx-installed') === 'true')
                ->click('[data-sx-app-action="sxpro.demo"]')
                ->waitUsing(5, 100, fn () => $browser->attribute('[data-sx-app-action="sxpro.demo"]', 'data-sx-installed') === 'false');

            // The launcher is now empty: the empty-state hint shows, ZERO tiles.
            $browser->click('[data-sx-launcher]')
                ->waitFor('.sx-launcher-grid')
                ->waitFor('[data-sx-launcher-empty]')
                ->assertPresent('[data-sx-launcher-empty]')
                ->assertScript("return document.querySelectorAll('.sx-launcher-grid [data-sx-launch]').length", 0)
                ->screenshot('app-install-empty-launcher');

            // Manage apps is STILL reachable from the user menu -- the user is never stuck. Close the
            // launcher first, then prove the menu item is there.
            $this->closeLauncher($browser);
            $this->openUserMenu($browser);
            $browser->assertPresent('.sx-system-menu [data-sx-menu="apps"]');
        });
    }
}

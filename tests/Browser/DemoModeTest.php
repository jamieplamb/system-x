<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Group;
use Tests\DuskTestCase;

// The headline live-demo proof (showcase plan, Task 7). Unlike every other Browser test,
// this one needs demo mode ON -- so it does NOT use loginAsDemoUser (there is no credential
// form when the flag is on; /login serves the landing page instead). It walks the real
// visitor path: hit `/` as a guest -> the auth bounce lands on the demo landing -> press
// "Launch demo" -> an ephemeral is_demo user is minted, logged in, and dropped on the desktop
// with the welcome window open. Then it opens a NON-seeded app from the launcher + moves a
// window and reloads: the open set survives, which is the load-bearing proof the throwaway
// user is a REAL, durable user (its windows persist in system_x_open_windows keyed by the
// user, exactly like a normal login).
//
// GROUPED 'demo' so CI can run it in its OWN flag-on step (SYSTEM_X_DEMO_MODE=true). The main
// Dusk run excludes this group and runs flag-OFF, because with the flag on /login serves the
// demo landing (not the credential form) and every other Browser test's form-login breaks. See
// .github/workflows/ci.yml (the dusk job's two-run split) and the live-demo runbook.
#[Group('demo')]
class DemoModeTest extends DuskTestCase
{
    public function test_visitor_launches_and_state_persists(): void
    {
        $this->browse(function (Browser $browser): void {
            // Start as a guaranteed guest so a session left behind by a prior method can't skip
            // the landing (the desktop `guest`-gate would 302 an authed session straight to `/`).
            // deleteAllCookies only clears the current origin, so land on the app first.
            $browser->visit('/login');
            $browser->driver->manage()->deleteAllCookies();
            $browser->script('try { window.localStorage.clear(); window.sessionStorage.clear(); } catch (e) {}');

            // The `/` auth bounce lands the guest on the demo landing (LoginController swaps the
            // greeter for demo.landing when the flag is on). The landing carries the launch form.
            $browser->visit('/')
                ->assertPathIs('/login')
                ->assertSee('Launch the demo')
                ->press('Launch the demo')
                // DemoController seeds the hello/notes example pair, opens the welcome window on
                // top, logs the is_demo user in, and redirects to `/`. So the demo desktop boots
                // populated -- welcome + hello + notes. Assert on the welcome window specifically.
                ->waitForLocation('/')
                ->waitFor('.sx-window-surface[data-app="welcome"] .sx-window')
                ->assertSee('This desktop is yours.');

            // The boot desktop carries the SEEDED set (welcome + hello + notes). Opening any of
            // those from the launcher is a no-op (the singleton path just focuses the existing
            // window) and proves nothing about persistence. So open `controls` -- a registered
            // USER app that is NOT in the seed set -- to mint a real, launched (fresh ULID) window
            // whose survival across the reload is the durable-user proof.
            $this->openViaLauncher($browser, 'controls');
            $browser->waitUsing(10, 100, fn (): bool => $browser->script(
                "return !!document.querySelector('.sx-window-surface[data-app=\"controls\"]');"
            )[0]);

            // Move the welcome window by dragging its titlebar -- a real WM interaction the visitor
            // would do. Synthesize PointerEvents (Dusk's ->drag is unreliable in a windowed WM),
            // the same pattern WindowManagerTest uses. Read the surface id off data-app first.
            $welcomeId = $browser->script(
                "return document.querySelector('.sx-window-surface[data-app=\"welcome\"]').dataset.windowId;"
            )[0];
            $this->assertNotEmpty($welcomeId, 'the welcome window has no id');

            $before = json_decode($browser->script(
                "const s = document.querySelector('[data-window-id=\"{$welcomeId}\"]');"
                .'return JSON.stringify({ x: s.dataset.sxX, y: s.dataset.sxY });'
            )[0], true);

            $browser->script(<<<JS
                const surface = document.querySelector('[data-window-id="{$welcomeId}"]');
                const bar = surface.querySelector('.sx-titlebar-text') || surface.querySelector('.sx-titlebar');
                const r = bar.getBoundingClientRect();
                const sx = r.left + r.width / 2;
                const sy = r.top + r.height / 2;
                const fire = (type, x, y, target) =>
                    target.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true, pointerId: 1, clientX: x, clientY: y }));
                fire('pointerdown', sx, sy, bar);
                fire('pointermove', sx + 90, sy + 70, window);
                fire('pointermove', sx + 90, sy + 70, window);
            JS);
            $browser->pause(120)
                ->script("window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerId: 1 }));");
            $browser->pause(120);

            $after = json_decode($browser->script(
                "const s = document.querySelector('[data-window-id=\"{$welcomeId}\"]');"
                .'return JSON.stringify({ x: s.dataset.sxX, y: s.dataset.sxY });'
            )[0], true);
            $this->assertNotEquals($before['x'], $after['x'], 'the welcome window did not move on the x axis');
            $this->assertNotEquals($before['y'], $after['y'], 'the welcome window did not move on the y axis');

            $browser->screenshot('demo-mode-desktop');

            // THE PROOF: reload. The ephemeral user is a real, durable user, so its open set is
            // restored from system_x_open_windows -- BOTH the welcome window (opened at launch) and
            // the controls window (a fresh ULID launched from the launcher) come back with their
            // content. If the demo user were a throwaway in-memory shell, the reload would land on
            // an empty desktop (or bounce back to the landing).
            $browser->refresh()
                ->waitFor('.sx-window-surface[data-app="welcome"] .sx-window')
                ->assertSee('This desktop is yours.')
                ->waitFor('.sx-window-surface[data-app="controls"] .sx-window');

            $browser->screenshot('demo-mode-after-reload');
        });
    }

    // Drive the launcher (mirrors WmLaunchTest::openViaLauncher): click the panel's system-x
    // button to open the overlay, wait for the app's tile, click it. Body-mounted, so the panel
    // button + the tile live outside #sx-desktop and a real ->click() resolves them.
    private function openViaLauncher(Browser $browser, string $slug): void
    {
        $browser->click('[data-sx-launcher]')
            ->waitFor("[data-sx-launch=\"{$slug}\"]")
            ->click("[data-sx-launch=\"{$slug}\"]")
            ->waitUntilMissing('[data-sx-launch]', 10);
    }
}

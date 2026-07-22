<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class WindowManagerTest extends DuskTestCase
{
    // The window manager (Plan 5a) owns geometry/focus/z on the .sx-window-surface,
    // CLIENT-ONLY (D1). This proves the marquee interaction -- drag-to-move (Task 4,
    // D6) -- and the D3 invariant that a server frame can't fight the dragged position.
    // The rest of the WM suite (focus/z, maximise) is finalized in Task 11.

    public function test_dragging_a_titlebar_moves_the_window_and_a_server_frame_cannot_fight_it(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="notes"] .sx-window');

            // Read hello's starting position (WM-owned data on the surface).
            $before = $browser->script(
                "return JSON.stringify({ x: window.document.querySelector('[data-window-id=\"hello\"]').dataset.sxX, "
                ."y: window.document.querySelector('[data-window-id=\"hello\"]').dataset.sxY });"
            )[0];
            $before = json_decode($before, true);

            // Drive a real pointer drag on hello's titlebar: down -> move -> up. We
            // synthesize PointerEvents (Dusk's ->drag is unreliable in a windowed WM)
            // and let the WM's own handlers + rAF run, then flush a frame.
            $browser->script(<<<'JS'
                const surface = document.querySelector('[data-window-id="hello"]');
                const bar = surface.querySelector('.sx-titlebar-text') || surface.querySelector('.sx-titlebar');
                const r = bar.getBoundingClientRect();
                const sx = r.left + r.width / 2;
                const sy = r.top + r.height / 2;
                const fire = (type, x, y, target) =>
                    target.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true, pointerId: 1, clientX: x, clientY: y }));
                fire('pointerdown', sx, sy, bar);
                fire('pointermove', sx + 120, sy + 90, window);
                fire('pointermove', sx + 120, sy + 90, window);
            JS);

            // Let two rAF batches apply the transform, then drop.
            $browser->pause(120)
                ->script("const s = document.querySelector('[data-window-id=\"hello\"]'); window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerId: 1 }));");

            $browser->pause(120);

            $after = $browser->script(
                "return JSON.stringify({ x: document.querySelector('[data-window-id=\"hello\"]').dataset.sxX, "
                ."y: document.querySelector('[data-window-id=\"hello\"]').dataset.sxY, "
                ."t: document.querySelector('[data-window-id=\"hello\"]').style.transform });"
            )[0];
            $after = json_decode($after, true);

            // The window MOVED -- both axes shifted (within rounding) by the drag delta.
            $this->assertNotEquals($before['x'], $after['x'], 'hello did not move on the x axis');
            $this->assertNotEquals($before['y'], $after['y'], 'hello did not move on the y axis');
            $this->assertStringContainsString('translate3d(', $after['t']);

            // D3: a server frame that morphs the dragged window's OWN content (a click
            // round-trip on hello's clicker) must reconcile into the .sx-window WITHOUT
            // snapping the surface back -- geometry lives on the surface the morph never
            // touches. The strongest form of the invariant: the frame hits this very window.
            $browser->click('[data-window-id="hello"] [data-sx-id="clicker"]')
                ->waitForTextIn('[data-window-id="hello"] [data-sx-id="counter"]', 'Clicked 1 times', 10);

            $afterFrame = $browser->script(
                "return document.querySelector('[data-window-id=\"hello\"]').style.transform;"
            )[0];

            $this->assertEquals(
                $after['t'],
                $afterFrame,
                'a server frame fought the dragged position (D3 violated)'
            );

            // Screenshot the moved window for the record.
            $browser->screenshot('window-manager-drag-moved');
        });
    }

    // Maximise/restore (Plan 5a, Task 5, D5): the maximise control fills the work area and
    // restore returns the window to its prior rect. Client-only (D1) -- it never POSTs.
    public function test_maximise_fills_the_work_area_and_restore_returns_to_the_prior_rect(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window');

            // The pre-max rect (WM-owned data on the surface).
            $before = $browser->script(
                "return JSON.stringify({ x: document.querySelector('[data-window-id=\"hello\"]').dataset.sxX, "
                ."y: document.querySelector('[data-window-id=\"hello\"]').dataset.sxY });"
            )[0];
            $before = json_decode($before, true);

            // Toggle maximise via the control with a REAL Selenium click (Plan 5b, Task 7, D6).
            // The floating logout <form> that used to overlap a maximised window's top-right
            // control row is GONE (logout moved into the panel tray), so nothing intercepts the
            // click -- this now proves the WM wiring AND the hit-testing end to end.
            $browser->click('[data-window-id="hello"] [data-sx-control="maximise"]');
            $browser->pause(150);

            $maxed = $browser->script(
                "const s = document.querySelector('[data-window-id=\"hello\"]'); "
                .'return JSON.stringify({ max: s.dataset.sxMax, t: s.style.transform, w: s.style.width, '
                ."restore: !!s.querySelector('[data-sx-control=\"restore\"]'), "
                ."winWidth: getComputedStyle(s.querySelector('.sx-window')).width, "
                ."mount: document.getElementById('sx-desktop').clientWidth });"
            )[0];
            $maxed = json_decode($maxed, true);

            // Maximised: flagged, pinned to the work-area origin, surface spans the mount,
            // the control flipped to restore, and the .sx-window FILLS the surface (CSS).
            $this->assertEquals('true', $maxed['max']);
            // Maximise now fills BELOW the top panel (Plan 5b, D7) -- the origin insets to the
            // panel height, not (0,0).
            $this->assertStringContainsString('translate3d(0px, 34px', $maxed['t']);
            $this->assertEquals($maxed['mount'].'px', $maxed['w'], 'surface did not fill the work-area width');
            $this->assertTrue($maxed['restore'], 'the maximise control did not flip to restore');
            $this->assertEquals($maxed['mount'].'px', $maxed['winWidth'], '.sx-window did not fill its surface (CSS width:100%)');

            // Screenshot the maximised window for the record.
            $browser->screenshot('window-manager-maximised');

            // Restore via the (now) restore control -- back to the prior place, control flips
            // back. A REAL click (Task 7, D6): the logout overlap is gone, so the restore button
            // on a maximised window is reachable by Selenium.
            $browser->click('[data-window-id="hello"] [data-sx-control="restore"]');
            $browser->pause(150);

            $restored = $browser->script(
                "const s = document.querySelector('[data-window-id=\"hello\"]'); "
                .'return JSON.stringify({ max: s.dataset.sxMax, x: s.dataset.sxX, y: s.dataset.sxY, '
                ."maximise: !!s.querySelector('[data-sx-control=\"maximise\"]') });"
            )[0];
            $restored = json_decode($restored, true);

            $this->assertEquals('false', $restored['max']);
            $this->assertEquals($before['x'], $restored['x'], 'restore did not return the x position');
            $this->assertEquals($before['y'], $restored['y'], 'restore did not return the y position');
            $this->assertTrue($restored['maximise'], 'the restore control did not flip back to maximise');
        });
    }

    // Double-click the titlebar toggles maximise -- in a REAL browser. The jsdom unit test for this
    // dispatches a synthetic dblclick straight at the element, which proves the handler's selectors
    // but NOT that a real double-click ever produces a dblclick that reaches the mount. Drag-arming
    // on the same pointerdown (setPointerCapture + preventDefault) can suppress/retarget the browser's
    // compatibility click events, and only a real driver catches that.
    public function test_double_clicking_the_titlebar_toggles_maximise(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window');

            // A REAL WebDriver double-click on the title text (not a control).
            $browser->doubleClick('[data-window-id="hello"] .sx-titlebar-text');
            $browser->pause(200);

            $maxed = $browser->script(
                "const s = document.querySelector('[data-window-id=\"hello\"]'); "
                // ?? 'unset': JSON.stringify DROPS undefined-valued keys, so a never-maximised
                // surface would come back with no 'max' key at all and blow up as a missing index
                // instead of failing the assertion cleanly.
                .'return JSON.stringify({ max: s.dataset.sxMax ?? \'unset\', '
                ."restore: !!s.querySelector('[data-sx-control=\"restore\"]') });"
            )[0];
            $maxed = json_decode($maxed, true);

            $this->assertEquals('true', $maxed['max'], 'double-clicking the titlebar did not maximise');
            $this->assertTrue($maxed['restore'], 'the maximise control did not flip to restore');

            // And again to restore -- the toggle has to work both ways.
            $browser->doubleClick('[data-window-id="hello"] .sx-titlebar-text');
            $browser->pause(200);

            $restored = $browser->script(
                "const s = document.querySelector('[data-window-id=\"hello\"]'); "
                .'return JSON.stringify({ max: s.dataset.sxMax });'
            )[0];
            $restored = json_decode($restored, true);

            $this->assertEquals('false', $restored['max'], 'double-clicking a maximised titlebar did not restore');
        });
    }

    // Close (Plan 5a, Task 9, D7): clicking the close control removes the surface client-side
    // AND POSTs /system-x/wm/close, which drops the open-row and FORGETS the bag. Close-then-
    // relaunch then mints a FRESH window (the old row is gone, so launch isn't a singleton hit).
    public function test_closing_a_window_removes_it_and_relaunch_mints_a_fresh_window(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="notes"] .sx-window');

            // Click notes' close control with a REAL Selenium click (Task 7, D6 -- the logout
            // overlap is gone). The surface should vanish from the DOM + the WM map.
            $browser->click('[data-window-id="notes"] [data-sx-control="close"]');

            $browser->waitUntilMissing('[data-window-id="notes"]', 10);

            // The open-row is gone server-side too -- a relaunch must mint a NEW window id, not
            // re-focus the closed one. Drive launch through the exposed display server (window.sx)
            // since the styled launcher is Task 10/5b.
            $browser->script("window.sx.launch('notes');");
            $browser->pause(400);

            $freshId = $browser->script(
                "const s = document.querySelector('.sx-window-surface[data-app=\"notes\"]'); "
                .'return s ? s.dataset.windowId : null;'
            )[0];

            // A genuinely-new window: a ULID (26 chars), NOT the slug-as-id 'notes' the closed
            // static window used. The close reaped the old row, so launch isn't a singleton hit.
            $this->assertNotNull($freshId, 'relaunching notes did not mint a surface');
            $this->assertNotEquals('notes', $freshId, 'relaunch re-used the closed window id (it should be fresh)');
            $this->assertEquals(26, strlen($freshId), 'the relaunched window is not a fresh ULID');

            $browser->screenshot('window-manager-close-then-relaunch');
        });
    }
}

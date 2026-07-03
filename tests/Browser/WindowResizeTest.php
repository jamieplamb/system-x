<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class WindowResizeTest extends DuskTestCase
{
    // Plan 5d: drag-to-resize. The WM owns SURFACE geometry (data-sx-sized + width/height +
    // the transform), CLIENT-ONLY (D1). The Vitest suite proves the geometry math in jsdom;
    // this proves the THREE browser-only behaviours jsdom CANNOT (no layout, no hit-testing):
    //   B2 -- a window can be shrunk SHORTER than its initial height hint (min-height:0 works).
    //   B3 -- a resized-THEN-maximised window renders at WORK-AREA size (the cascade tie).
    //   B4 -- the handles don't steal the titlebar drag or the close button (z-index/inset).
    // Plus the marquee interaction (SE-corner grow, W-edge origin-shift), the D3 frame
    // survival, and the maximised-handles-inert guard.

    // A reusable JS pointer-drag of a [data-sx-resize] handle: grab the handle centre, move by
    // (dx,dy) across a couple of frames, drop. Mirrors the titlebar-drag synthesis in
    // WindowManagerTest (Dusk's ->drag is unreliable in a windowed WM). Returns nothing; the
    // caller reads the settled surface geometry after a short pause for the rAF batch.
    private function dragHandle(Browser $browser, string $windowId, string $dir, int $dx, int $dy): void
    {
        $browser->script(<<<JS
            const surface = document.querySelector('[data-window-id="{$windowId}"]');
            const handle = surface.querySelector('[data-sx-resize="{$dir}"]');
            const r = handle.getBoundingClientRect();
            const sx = r.left + r.width / 2;
            const sy = r.top + r.height / 2;
            const fire = (type, x, y, target) =>
                target.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true, pointerId: 1, clientX: x, clientY: y }));
            fire('pointerdown', sx, sy, handle);
            fire('pointermove', sx + ({$dx}), sy + ({$dy}), window);
            fire('pointermove', sx + ({$dx}), sy + ({$dy}), window);
        JS);
        $browser->pause(120)
            ->script("window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerId: 1 }));");
        $browser->pause(120);
    }

    // Read the settled rect (x/y from the WM data, w/h from the surface inline px) plus the
    // RENDERED inner .sx-window box -- the browser-only layout result jsdom can't compute.
    private function geometry(Browser $browser, string $windowId): array
    {
        $json = $browser->script(<<<JS
            const s = document.querySelector('[data-window-id="{$windowId}"]');
            const win = s.querySelector('.sx-window');
            const cs = getComputedStyle(win);
            return JSON.stringify({
                sized: s.dataset.sxSized || 'false',
                x: Number(s.dataset.sxX),
                y: Number(s.dataset.sxY),
                w: parseInt(s.style.width, 10) || 0,
                h: parseInt(s.style.height, 10) || 0,
                t: s.style.transform,
                winW: parseFloat(cs.width),
                winH: parseFloat(cs.height),
                mountW: document.getElementById('sx-desktop').clientWidth,
                mountH: document.getElementById('sx-desktop').clientHeight,
            });
        JS)[0];

        return json_decode($json, true);
    }

    // The marquee interaction: SE-corner grow + W-edge origin-shift, end to end in a browser.
    public function test_dragging_the_se_corner_grows_the_window_and_the_w_edge_shifts_the_origin(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="hello"] [data-sx-resize="se"]');

            // Read the natural (un-sized) rendered size first -- the SE drag must grow it.
            $before = $this->geometry($browser, 'hello');

            // SE corner: drag down-right -> both width and height grow, origin fixed.
            $this->dragHandle($browser, 'hello', 'se', 160, 120);
            $grown = $this->geometry($browser, 'hello');

            $this->assertEquals('true', $grown['sized'], 'the SE drag did not mark the surface data-sx-sized');
            $this->assertGreaterThan($before['winW'], $grown['winW'], 'the SE drag did not grow the rendered width');
            $this->assertGreaterThan($before['winH'], $grown['winH'], 'the SE drag did not grow the rendered height');
            // The .sx-window FILLS the sized surface (the CSS fill rule) -- rendered ~= surface px.
            $this->assertEqualsWithDelta($grown['w'], $grown['winW'], 2, '.sx-window did not fill the sized surface width');

            // W edge: drag LEFT -> the window widens leftward (x decreases, width increases),
            // the right edge stays anchored. This is the origin-shift half (D2/B1). Keep the drag
            // SMALL so the moving left edge stays clear of the desktop's left edge (x=0) -- a larger
            // drag would hit the work-area origin clamp (proven separately in Vitest), which floors
            // x at 0 and lets the right edge absorb the overflow, masking the anchor invariant.
            $xBefore = $grown['x'];
            $wBefore = $grown['w'];
            $rightBefore = $grown['x'] + $grown['w'];
            $this->dragHandle($browser, 'hello', 'w', -20, 0);
            $widened = $this->geometry($browser, 'hello');

            $this->assertLessThan($xBefore, $widened['x'], 'the W drag did not move the origin left');
            $this->assertGreaterThan($wBefore, $widened['w'], 'the W drag did not widen the window');
            $this->assertEqualsWithDelta($rightBefore, $widened['x'] + $widened['w'], 2, 'the W drag did not keep the right edge anchored');

            $browser->screenshot('window-resize-grown-and-widened');
        });
    }

    // B2 (browser-only): resize a window SHORTER than its declared height. The renderer stamps
    // style.height on the .sx-window as a CAP (window.js, V1-A); only the [data-sx-sized] fill
    // rule's height:100% !important lets the window actually shrink below it. jsdom computes no
    // layout, so ONLY a real browser proves the neutralisation works -- rendered .sx-window height
    // < the declared height.
    public function test_b2_a_window_shrinks_below_its_initial_height_hint(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="hello"] [data-sx-resize="s"]');

            // The declared height the renderer stamped as a cap (style.height on the .sx-window).
            // HelloApp::size() drives it; read it live rather than hardcoding the default.
            $hint = (int) $browser->script(
                "return parseInt(document.querySelector('[data-window-id=\"hello\"] .sx-window').style.height, 10) || 0;"
            )[0];
            $this->assertGreaterThan(0, $hint, 'the .sx-window has no declared height to shrink below');

            // Drag the S (bottom) edge UP hard -- past the declared height, down toward MIN_H (90).
            // The floor stops it at MIN_H; the point is it goes BELOW the declared height at all.
            $this->dragHandle($browser, 'hello', 's', 0, -($hint + 300));
            $shrunk = $this->geometry($browser, 'hello');

            $this->assertEquals('true', $shrunk['sized']);
            $this->assertLessThan(
                $hint,
                $shrunk['winH'],
                "the .sx-window ({$shrunk['winH']}px) did not shrink below its declared height ({$hint}px) -- the sized fill rule failed (B2)"
            );
            // It stopped at the MIN_H floor (90), not collapsed to nothing.
            $this->assertGreaterThanOrEqual(88, $shrunk['winH'], 'the window shrank past the MIN_H floor');

            $browser->screenshot('window-resize-b2-shrunk-below-hint');
        });
    }

    // B3 (browser-only): a resized-THEN-maximised window must render at WORK-AREA size, not its
    // sized size. It matches BOTH the [data-sx-sized] fill rule and the [data-sx-max] rule on
    // .sx-window (equal specificity); both are !important, so the tie resolves by source order
    // and maximise is kept LAST. jsdom computes no layout, so only the browser proves the tie.
    public function test_b3_a_resized_then_maximised_window_renders_at_work_area_size(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="hello"] [data-sx-resize="se"]');

            // Resize SMALL first (SW up-left so it stays well under the work area), so "sized
            // size" and "work-area size" are unmistakably different.
            $this->dragHandle($browser, 'hello', 'se', -120, -120);
            $sized = $this->geometry($browser, 'hello');
            $this->assertEquals('true', $sized['sized']);

            // Now maximise it.
            $browser->click('[data-window-id="hello"] [data-sx-control="maximise"]')
                ->pause(180);

            $maxed = $this->geometry($browser, 'hello');

            // The RENDERED .sx-window fills the WORK AREA, NOT its (smaller) sized size -- the
            // maximise rule won the equal-specificity tie (B3).
            $this->assertEqualsWithDelta(
                $maxed['mountW'],
                $maxed['winW'],
                2,
                'a resized-then-maximised window did not fill the work-area WIDTH (B3 cascade tie lost)'
            );
            $this->assertGreaterThan(
                $sized['w'],
                $maxed['winW'],
                'the maximised width is still the sized width -- the sized rule beat maximise (B3)'
            );
            // Height fills the work area (clientHeight - PANEL_H), not the sized height.
            $this->assertEqualsWithDelta($maxed['mountH'] - 34, $maxed['winH'], 2, 'maximise did not fill the work-area HEIGHT');

            $browser->screenshot('window-resize-b3-maximised-over-sized');
        });
    }

    // B4 (browser-only, the hit-test landmine): the thin handle frame sits UNDER the chrome
    // (z-index) and the top handles are INSET, so grabbing the TITLEBAR still MOVES the window
    // (not resizes) and the CLOSE button (under the NE corner) still CLOSES. jsdom does no
    // hit-testing, so this can ONLY be proven in a browser.
    public function test_b4_the_handles_do_not_steal_the_titlebar_drag_or_the_close_button(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="notes"] .sx-window');

            // --- B4a: a drag from the titlebar TOP still MOVES (transform changes, size doesn't) ---
            $before = $this->geometry($browser, 'hello');

            // Grab the titlebar near its TOP edge -- right where the N handle strip would sit if
            // it stole the press -- and drag. If the handle stole it the window would RESIZE
            // (data-sx-sized) instead of moving (transform changes, no sized flag).
            $browser->script(<<<'JS'
                const surface = document.querySelector('[data-window-id="hello"]');
                const bar = surface.querySelector('.sx-titlebar');
                const r = bar.getBoundingClientRect();
                // x clear of the corner squares + the control buttons; y at the very TOP of the bar.
                const sx = r.left + r.width * 0.4;
                const sy = r.top + 1;
                const fire = (type, x, y, target) =>
                    target.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true, pointerId: 1, clientX: x, clientY: y }));
                fire('pointerdown', sx, sy, document.elementFromPoint(sx, sy));
                fire('pointermove', sx + 110, sy + 80, window);
                fire('pointermove', sx + 110, sy + 80, window);
            JS);
            $browser->pause(120);
            $browser->script("window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerId: 1 }));");
            $browser->pause(120);

            $afterDrag = $this->geometry($browser, 'hello');

            $this->assertNotEquals($before['t'], $afterDrag['t'], 'the titlebar-top press did not MOVE the window (a handle stole the drag, B4)');
            $this->assertNotEquals($before['x'], $afterDrag['x'], 'the titlebar-top drag did not shift x');
            $this->assertNotEquals('true', $afterDrag['sized'], 'the titlebar-top press RESIZED instead of moving -- a handle stole the press (B4)');

            // --- B4b: the CLOSE button (under the NE corner) still CLOSES, not resizes ---
            // notes is a clean second window; close it via a REAL Selenium click on the control
            // that sits flush top-right, right where the NE corner square overlaps.
            $browser->click('[data-window-id="notes"] [data-sx-control="close"]')
                ->waitUntilMissing('[data-window-id="notes"]', 10);

            // It's gone -- the close press hit the button, not the NE resize handle.
            $stillThere = $browser->script("return !!document.querySelector('[data-window-id=\"notes\"]');")[0];
            $this->assertFalse((bool) $stillThere, 'the close button under the NE corner did not close the window (a handle stole it, B4)');
        });
    }

    // The D3 invariant for resize + the maximised-handles-inert guard, in a browser.
    public function test_resize_survives_a_server_frame_and_maximised_handles_are_inert(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="hello"] [data-sx-resize="se"]');

            // Resize, then read the settled geometry.
            $this->dragHandle($browser, 'hello', 'se', 140, 100);
            $resized = $this->geometry($browser, 'hello');
            $this->assertEquals('true', $resized['sized']);

            // D3: push a server frame at THIS window (a click round-trip on hello's clicker) --
            // the morph reconciles into the .sx-window and must NOT fight the surface geometry.
            $browser->click('[data-window-id="hello"] [data-sx-id="clicker"]')
                ->waitForTextIn('[data-window-id="hello"] [data-sx-id="counter"]', 'Clicked 1 times', 10);

            $afterFrame = $this->geometry($browser, 'hello');
            $this->assertEquals($resized['w'], $afterFrame['w'], 'a server frame fought the resized width (D3 violated)');
            $this->assertEquals($resized['h'], $afterFrame['h'], 'a server frame fought the resized height (D3 violated)');
            $this->assertEquals($resized['t'], $afterFrame['t'], 'a server frame fought the resized position (D3 violated)');

            // The maximised window's handles are inert: display:none (CSS) AND the WM guard.
            $browser->click('[data-window-id="hello"] [data-sx-control="maximise"]')
                ->pause(180);

            $handleHidden = $browser->script(
                "return getComputedStyle(document.querySelector('[data-window-id=\"hello\"] [data-sx-resize=\"se\"]')).display;"
            )[0];
            $this->assertEquals('none', $handleHidden, 'a maximised window still shows its resize handles');

            // And the guard: even firing a pointer sequence on the (hidden) handle must NOT resize.
            $maxedBefore = $this->geometry($browser, 'hello');
            $this->dragHandle($browser, 'hello', 'se', 200, 200);
            $maxedAfter = $this->geometry($browser, 'hello');
            $this->assertEquals($maxedBefore['w'], $maxedAfter['w'], 'a maximised window resized from its handle (the guard failed)');
            $this->assertEquals($maxedBefore['t'], $maxedAfter['t'], 'a maximised window moved from a resize drag');

            $browser->screenshot('window-resize-maximised-handles-inert');
        });
    }

    // The record shot: a couple of windows at custom sizes on the desktop.
    public function test_a_resized_desktop_screenshot(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="notes"] .sx-window');

            // hello: grow it wide via the SE corner.
            $this->dragHandle($browser, 'hello', 'se', 200, 120);
            // notes: shrink it narrow via the SE corner (up-left), a clearly different size.
            $this->dragHandle($browser, 'notes', 'se', -80, -40);

            $hello = $this->geometry($browser, 'hello');
            $notes = $this->geometry($browser, 'notes');
            $this->assertEquals('true', $hello['sized']);
            $this->assertEquals('true', $notes['sized']);
            $this->assertNotEquals($hello['w'], $notes['w'], 'the two windows ended at the same size -- the shot should show distinct custom sizes');

            $browser->screenshot('window-resize-desktop-custom-sizes');
        });
    }
}

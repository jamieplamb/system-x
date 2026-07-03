<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class WindowSnapTest extends DuskTestCase
{
    // Plan 5f: drag-to-edge window snapping (aero-snap). Drag a titlebar to a screen edge/corner
    // to TILE the window -- left/right edges = halves, the four corners = quarters, the top edge =
    // maximise -- with a translucent .sx-snap-ghost preview shown mid-drag. The zone/rect geometry,
    // the ghost helpers, and the drag integration are proven in Vitest (jsdom). This is the round-
    // trip jsdom CAN'T show: a REAL browser tiling against a real work area, the ghost appearing
    // mid-drag and gone on release, and the headline -- a snap SURVIVES A RELOAD (it rides the 5e
    // geometry; a tiled window is just a sized/maximised window, so reload restores it for free).
    //
    // The work area is the desktop mount minus the 34px top panel (PANEL_H). A left/right half is
    // ~mount.clientWidth/2 wide at x=0 / x=W/2; a quarter is ~W/2 x workH/2 at the zone's corner.

    private const PANEL_H = 34;

    // Drag a titlebar to a target VIEWPORT point and DROP -- the snap commits from the zone the
    // last pointermove computed (Plan 5f, D4: onUp reads the stored zone, not a fresh coord read).
    // Grab the titlebar centre, move to (tx,ty) across two frames (the WM arms the zone + ghost on
    // the raw pointermove), pause for the rAF, then pointerup. Mirrors the drag synthesis the 5d/5e
    // Dusk suites use (Dusk's ->drag is unreliable in a windowed WM).
    private function dragTitlebarTo(Browser $browser, string $windowId, int $tx, int $ty): void
    {
        $browser->script(<<<JS
            const surface = document.querySelector('[data-window-id="{$windowId}"]');
            const bar = surface.querySelector('.sx-titlebar-text') || surface.querySelector('.sx-titlebar');
            const r = bar.getBoundingClientRect();
            const sx = r.left + r.width / 2;
            const sy = r.top + r.height / 2;
            const fire = (type, x, y, target) =>
                target.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true, pointerId: 1, clientX: x, clientY: y }));
            fire('pointerdown', sx, sy, bar);
            fire('pointermove', {$tx}, {$ty}, window);
            fire('pointermove', {$tx}, {$ty}, window);
        JS);
        $browser->pause(120)
            ->script("window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerId: 1 }));");
        $browser->pause(180); // let the rAF + the resize/maximise settle AND the geometry POST leave
    }

    // Read a surface's WM-owned geometry (the data + the inline style + the RENDERED inner box).
    private function geometry(Browser $browser, string $windowId): array
    {
        $json = $browser->script(<<<JS
            const s = document.querySelector('[data-window-id="{$windowId}"]');
            if (!s) return JSON.stringify({ missing: true });
            const win = s.querySelector('.sx-window');
            const cs = win ? getComputedStyle(win) : null;
            return JSON.stringify({
                x: Number(s.dataset.sxX),
                y: Number(s.dataset.sxY),
                w: parseInt(s.style.width, 10) || 0,
                h: parseInt(s.style.height, 10) || 0,
                sized: s.dataset.sxSized || 'false',
                max: s.dataset.sxMax || 'false',
                t: s.style.transform,
                winW: cs ? parseFloat(cs.width) : 0,
                winH: cs ? parseFloat(cs.height) : 0,
                mountW: document.getElementById('sx-desktop').clientWidth,
                mountH: document.getElementById('sx-desktop').clientHeight,
            });
        JS)[0];

        return json_decode($json, true);
    }

    // Drag a window to the LEFT edge -> it tiles to the left half (x=0, ~half the work-area width,
    // full work height, data-sx-sized). The browser-only proof: a REAL work area, a REAL fill.
    public function test_dragging_a_titlebar_to_the_left_edge_tiles_the_left_half(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window');

            $mountW = (int) $browser->script("return document.getElementById('sx-desktop').clientWidth;")[0];

            // Drag to the LEFT edge at mid-height (a HALF, not a corner -- well inside the CORNER
            // bands at the top/bottom). y at half the mount height is the safe middle of the edge.
            $midY = (int) ($browser->script("return document.getElementById('sx-desktop').clientHeight;")[0] / 2);
            $this->dragTitlebarTo($browser, 'hello', 5, $midY);

            $snapped = $this->geometry($browser, 'hello');

            $this->assertEquals('true', $snapped['sized'], 'the left-edge drag did not mark the window sized (it did not snap)');
            $this->assertEquals(0, $snapped['x'], 'the left-half snap is not at x=0');
            // The surface width ~= half the work area (= half the mount width).
            $this->assertEqualsWithDelta($mountW / 2, $snapped['w'], 2, 'the left snap is not ~half the work-area width');
            // The .sx-window FILLS the sized surface (the CSS fill rule).
            $this->assertEqualsWithDelta($snapped['w'], $snapped['winW'], 2, 'the .sx-window did not fill the left-half surface');
            // Pinned to the left edge of the viewport (x=0 -> translate3d(0px, ...)).
            $this->assertStringContainsString('translate3d(0px,', $snapped['t'], 'the left-half snap is not pinned to x=0');
        });
    }

    // Drag a window to the TOP edge (mid-width) -> it MAXIMISES (top-snap calls maximise()):
    // data-sx-max='true', filling the work area.
    public function test_dragging_a_titlebar_to_the_top_edge_maximises(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window');

            $mountW = (int) $browser->script("return document.getElementById('sx-desktop').clientWidth;")[0];

            // Drag to the TOP edge at MID-WIDTH (outside the left/right CORNER bands) -> maximise.
            $this->dragTitlebarTo($browser, 'hello', (int) ($mountW / 2), 2);

            $maxed = $this->geometry($browser, 'hello');

            $this->assertEquals('true', $maxed['max'], 'the top-edge drag did not maximise the window');
            // It fills the work-area width and is pinned below the top panel.
            $this->assertEqualsWithDelta($maxed['mountW'], $maxed['winW'], 2, 'the top-snapped window does not fill the work-area width');
            $this->assertEqualsWithDelta($maxed['mountH'] - self::PANEL_H, $maxed['winH'], 2, 'the top-snapped window does not fill the work-area height');
            $this->assertStringContainsString('translate3d(0px, '.self::PANEL_H.'px', $maxed['t'], 'the top-snapped window is not pinned below the top panel');
        });
    }

    // Drag a window to a CORNER -> it tiles to a QUARTER (~half the work-area width AND ~half the
    // work height, at the corner origin). Proves the corner band is hittable (B1) end to end.
    public function test_dragging_a_titlebar_to_a_corner_tiles_a_quarter(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window');

            $mountW = (int) $browser->script("return document.getElementById('sx-desktop').clientWidth;")[0];
            $mountH = (int) $browser->script("return document.getElementById('sx-desktop').clientHeight;")[0];
            $workH = $mountH - self::PANEL_H;

            // Top-left CORNER: x near the left edge, y near the top (well inside the ~120px CORNER
            // band but below the panel). Lands the pointer in the 'tl' zone.
            $this->dragTitlebarTo($browser, 'hello', 4, self::PANEL_H + 6);

            $quarter = $this->geometry($browser, 'hello');

            $this->assertEquals('true', $quarter['sized'], 'the corner drag did not mark the window sized (it did not snap to a quarter)');
            $this->assertEquals(0, $quarter['x'], 'the top-left quarter is not at x=0');
            // A quarter: ~half the work-area width, ~half the work height.
            $this->assertEqualsWithDelta($mountW / 2, $quarter['w'], 2, 'the corner snap is not ~half the work-area width');
            $this->assertEqualsWithDelta($workH / 2, $quarter['h'], 2, 'the corner snap is not ~half the work-area height (not a quarter)');
            // Top-left quarter sits at the work-area top (below the panel) -> translate3d(0px, 34px,...).
            $this->assertStringContainsString('translate3d(0px, '.self::PANEL_H.'px', $quarter['t'], 'the top-left quarter is not pinned to the work-area origin');
        });
    }

    // The GHOST mid-drag: while the pointer hovers a snap zone (BEFORE release) the .sx-snap-ghost
    // is visible at the target rect; after release it is gone (hidden). Driven as a press-move-
    // assert-release sequence (the WM shows the ghost synchronously on the raw pointermove).
    public function test_the_snap_ghost_shows_mid_drag_and_hides_on_release(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window');

            $midY = (int) ($browser->script("return document.getElementById('sx-desktop').clientHeight;")[0] / 2);

            // PRESS + MOVE into the left zone, then STOP (no pointerup yet) -- the ghost is showing.
            $browser->script(<<<JS
                const surface = document.querySelector('[data-window-id="hello"]');
                const bar = surface.querySelector('.sx-titlebar-text') || surface.querySelector('.sx-titlebar');
                const r = bar.getBoundingClientRect();
                const sx = r.left + r.width / 2;
                const sy = r.top + r.height / 2;
                const fire = (type, x, y, target) =>
                    target.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true, pointerId: 1, clientX: x, clientY: y }));
                fire('pointerdown', sx, sy, bar);
                fire('pointermove', 5, {$midY}, window);
            JS);
            $browser->pause(80);

            // The ghost is on the body, NOT hidden, and sized to the left-half target rect.
            $ghostMid = json_decode($browser->script(<<<'JS'
                const g = document.querySelector('.sx-snap-ghost');
                if (!g) return JSON.stringify({ present: false });
                return JSON.stringify({
                    present: true,
                    hidden: g.hidden,
                    display: getComputedStyle(g).display,
                    w: parseFloat(g.style.width) || 0,
                    left: g.style.left,
                });
            JS)[0], true);

            $this->assertTrue($ghostMid['present'], 'the snap ghost was not created mid-drag');
            $this->assertFalse($ghostMid['hidden'], 'the snap ghost is hidden while the pointer hovers a zone');
            $this->assertNotEquals('none', $ghostMid['display'], 'the snap ghost is display:none mid-drag (not previewing)');
            $this->assertEquals('0px', $ghostMid['left'], 'the ghost is not positioned at the left-half origin (x=0)');

            // RELEASE -> the ghost hides (snapped or not, it must not linger -- D5).
            $browser->script("window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerId: 1 }));");
            $browser->pause(150);

            $ghostAfter = json_decode($browser->script(<<<'JS'
                const g = document.querySelector('.sx-snap-ghost');
                if (!g) return JSON.stringify({ present: false, hidden: true });
                return JSON.stringify({ present: true, hidden: g.hidden, display: getComputedStyle(g).display });
            JS)[0], true);

            // Either removed or hidden -- the WM hides (reuses the element); both mean "gone".
            $gone = ! $ghostAfter['present'] || $ghostAfter['hidden'] === true || $ghostAfter['display'] === 'none';
            $this->assertTrue($gone, 'the snap ghost lingered on-screen after release (a stuck overlay)');
        });
    }

    // THE HEADLINE: a snap SURVIVES A RELOAD. Snap hello to the left half, RELOAD, and it comes
    // back as the left half -- proving the tiled rect persisted via the 5e geometry (a snapped
    // window is just a sized window; the boot-restore tiles it again for free, no new column).
    public function test_a_snapped_window_survives_a_reload(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window');

            $mountW = (int) $browser->script("return document.getElementById('sx-desktop').clientWidth;")[0];
            $midY = (int) ($browser->script("return document.getElementById('sx-desktop').clientHeight;")[0] / 2);

            // Snap hello to the LEFT half.
            $this->dragTitlebarTo($browser, 'hello', 5, $midY);
            $before = $this->geometry($browser, 'hello');
            $this->assertEquals('true', $before['sized'], 'hello did not snap to the left half before reload');
            $this->assertEqualsWithDelta($mountW / 2, $before['w'], 2, 'hello is not the left half before reload');

            // Give the fire-and-forget geometry POST a beat to land, then RELOAD.
            $browser->pause(300)
                ->refresh()
                ->waitFor('[data-window-id="hello"] .sx-window', 10)
                ->pause(400); // let the WM ctor finish its boot-restore

            $after = $this->geometry($browser, 'hello');

            // It came back TILED: still sized, still the left half, still at x=0.
            $this->assertEquals('true', $after['sized'], 'hello lost its sized flag on reload -- the snap did not persist');
            $this->assertEquals(0, $after['x'], 'hello did not restore at the left edge (x=0) on reload');
            $this->assertEqualsWithDelta($before['w'], $after['w'], 2, 'hello did not restore its left-half width on reload');
            $this->assertEqualsWithDelta($before['h'], $after['h'], 2, 'hello did not restore its left-half height on reload');
            // The inner .sx-window still fills the tiled surface.
            $this->assertEqualsWithDelta($after['w'], $after['winW'], 2, 'the restored .sx-window did not fill the tiled surface');
        });
    }

    // The record shot: two windows tiled side by side -- hello LEFT half, notes RIGHT half --
    // the canonical snapped-desktop layout.
    public function test_a_tiled_desktop_screenshot(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="notes"] .sx-window');

            $mountW = (int) $browser->script("return document.getElementById('sx-desktop').clientWidth;")[0];
            $midY = (int) ($browser->script("return document.getElementById('sx-desktop').clientHeight;")[0] / 2);

            // hello -> LEFT half; notes -> RIGHT half.
            $this->dragTitlebarTo($browser, 'hello', 5, $midY);
            $this->dragTitlebarTo($browser, 'notes', $mountW - 5, $midY);

            $hello = $this->geometry($browser, 'hello');
            $notes = $this->geometry($browser, 'notes');

            $this->assertEquals('true', $hello['sized'], 'hello did not snap left');
            $this->assertEquals('true', $notes['sized'], 'notes did not snap right');
            $this->assertEquals(0, $hello['x'], 'hello is not at the left edge');
            $this->assertEqualsWithDelta($mountW / 2, $notes['x'], 2, 'notes is not at the right-half origin (W/2)');
            // Side by side: hello's right edge meets notes' left edge at ~the midline.
            $this->assertEqualsWithDelta($hello['w'], $notes['w'], 2, 'the two halves are not the same width');

            $browser->screenshot('window-snap-tiled-halves');
        });
    }
}

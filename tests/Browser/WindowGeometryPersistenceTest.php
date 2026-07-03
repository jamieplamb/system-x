<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class WindowGeometryPersistenceTest extends DuskTestCase
{
    // Plan 5e: window geometry persistence. The settle-capture (drag/resize/maximise/
    // minimise/raise -> a fire-and-forget POST /system-x/wm/geometry) + the boot-restore
    // (the blade stamps the saved rect on each surface, the WM applies it instead of
    // cascading + restores stacking/focus) are proven in PHPUnit + Vitest. This is the ONE
    // thing those can't show: the full round-trip through a real browser + a real RELOAD.
    //
    // The proof drives FOUR windows into distinct states, RELOADS, and asserts each comes
    // back where + how it was left:
    //   - hello   MOVED   -> data-sx-x/y at the offset (the surface transform)
    //   - notes   RESIZED -> data-sx-sized='true' + the surface width/height
    //   - appear  MAXED   -> data-sx-max='true' filling the work area
    //   - about   MINNED  -> data-sx-min='true' (display:none, in the panel)
    // plus the STACKING/FOCUS via the data-sx-active cue (hello raised last is active after
    // reload), and the B1 maximise-restore round-trip across reload (restore lands at the
    // persisted RESTORE rect, NOT the work-area fill -- proving the persisted rect drove the
    // pre-max stash on boot). We assert focus via data-sx-active, NEVER a raw z-index string.

    // The same JS pointer-drag of a [data-sx-resize] handle WindowResizeTest uses (Dusk's
    // ->drag is unreliable in a windowed WM). Grab the handle centre, move by (dx,dy), drop.
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
        $browser->pause(160); // let the rAF batch settle AND the fire-and-forget POST leave
    }

    // The same titlebar pointer-drag WindowManagerTest uses to MOVE a window (transform, no
    // resize). Grab the titlebar centre, move by (dx,dy), drop.
    private function dragTitlebar(Browser $browser, string $windowId, int $dx, int $dy): void
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
            fire('pointermove', sx + ({$dx}), sy + ({$dy}), window);
            fire('pointermove', sx + ({$dx}), sy + ({$dy}), window);
        JS);
        $browser->pause(120)
            ->script("window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerId: 1 }));");
        $browser->pause(160);
    }

    // Raise a window to the top via a REAL titlebar pointer press (the mount-level pointerdown
    // brings + focuses it). Used to clear overlaps before a control click AND to drive the
    // raise-to-top that the reload must restore as the active window.
    private function raise(Browser $browser, string $windowId): void
    {
        $browser->script(<<<JS
            const s = document.querySelector('[data-window-id="{$windowId}"]');
            const bar = s.querySelector('.sx-titlebar-text') || s.querySelector('.sx-titlebar');
            const rect = bar.getBoundingClientRect();
            const opts = { bubbles: true, cancelable: true, pointerId: 3, clientX: rect.left + rect.width / 2, clientY: rect.top + 2 };
            bar.dispatchEvent(new PointerEvent('pointerdown', opts));
            window.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, pointerId: 3 }));
        JS);
        $browser->pause(160);
    }

    // Read a surface's WM-owned geometry off its data + inline style (the morph never touches
    // it). Works even for a minimised surface (display:none) -- the attributes are still there.
    private function geometry(Browser $browser, string $windowId): array
    {
        $json = $browser->script(<<<JS
            const s = document.querySelector('[data-window-id="{$windowId}"]');
            if (!s) return JSON.stringify({ missing: true });
            return JSON.stringify({
                x: Number(s.dataset.sxX),
                y: Number(s.dataset.sxY),
                w: parseInt(s.style.width, 10) || 0,
                h: parseInt(s.style.height, 10) || 0,
                sized: s.dataset.sxSized || 'false',
                max: s.dataset.sxMax || 'false',
                min: s.dataset.sxMin || 'false',
                active: s.dataset.sxActive || 'false',
                t: s.style.transform,
                display: getComputedStyle(s).display,
                mountW: document.getElementById('sx-desktop').clientWidth,
                mountH: document.getElementById('sx-desktop').clientHeight,
            });
        JS)[0];

        return json_decode($json, true);
    }

    public function test_a_reload_restores_every_window_at_its_saved_geometry_stacking_and_focus(): void
    {
        $this->browse(function (Browser $browser): void {
            // Boot the seeded pair (hello + notes), then launch two more so we have FOUR
            // windows to drive into the four distinct states (move/resize/max/min).
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="notes"] .sx-window');

            $browser->script("window.sx.launch('appearance');");
            $browser->waitFor('.sx-window-surface[data-app="appearance"] .sx-window', 10);
            $browser->script("window.sx.launch('about');");
            $browser->waitFor('.sx-window-surface[data-app="about"] .sx-window', 10);

            // Resolve the launched ULIDs (about/appearance mint a ULID id, not a slug id).
            $appearId = $browser->script(
                "return document.querySelector('.sx-window-surface[data-app=\"appearance\"]').dataset.windowId;"
            )[0];
            $aboutId = $browser->script(
                "return document.querySelector('.sx-window-surface[data-app=\"about\"]').dataset.windowId;"
            )[0];

            // --- drive the four states (each settle fires a fire-and-forget geometry POST) ---

            // hello: MOVE it to a known offset via a titlebar drag.
            $this->dragTitlebar($browser, 'hello', 140, 100);
            $helloMoved = $this->geometry($browser, 'hello');
            $this->assertStringNotContainsString('40px, 40px', $helloMoved['t'], 'hello did not move off the cascade origin');

            // notes: RESIZE it bigger via the SE corner.
            $this->dragHandle($browser, 'notes', 'se', 180, 120);
            $notesResized = $this->geometry($browser, 'notes');
            $this->assertEquals('true', $notesResized['sized'], 'notes did not become sized');
            $this->assertGreaterThan(0, $notesResized['w'], 'notes has no persisted width');

            // appearance: RESIZE it to a known size, THEN MAXIMISE it. Raise it first (a real
            // titlebar press) so nothing overlaps its control row -- about launched last sits
            // above it, and its text input would intercept the Selenium click otherwise. The
            // explicit pre-resize gives it a concrete RESTORE rect (sized w/h), so the B1
            // restore-across-reload below is an exact-size proof, not just a position one.
            $this->raise($browser, $appearId);
            $this->dragHandle($browser, $appearId, 'se', -90, -70);
            $appearPreMax = $this->geometry($browser, $appearId);
            $this->assertEquals('true', $appearPreMax['sized'], 'appearance did not become sized before maximise');
            $browser->click('[data-window-id="'.$appearId.'"] [data-sx-control="maximise"]')->pause(220);
            $appearMaxed = $this->geometry($browser, $appearId);
            $this->assertEquals('true', $appearMaxed['max'], 'appearance did not maximise');

            // about: MINIMISE it (it drops to the panel; the surface goes display:none).
            $this->raise($browser, $aboutId);
            $browser->click('[data-window-id="'.$aboutId.'"] [data-sx-control="minimise"]')->pause(220);
            $aboutMinned = $this->geometry($browser, $aboutId);
            $this->assertEquals('true', $aboutMinned['min'], 'about did not minimise');
            $this->assertEquals('none', $aboutMinned['display'], 'a minimised surface is not hidden');

            // RAISE hello to the very top -- so hello is the focused/top window. (The raise
            // z-save is the GUARDED bring() persist; hello wasn't top after the launches, so
            // this actually advances its z and persists.)
            $this->raise($browser, 'hello');
            $this->assertEquals('true', $this->geometry($browser, 'hello')['active'], 'hello is not active after the raise');

            // Give every fire-and-forget POST a beat to land before we reload.
            $browser->pause(300);

            // ============================ THE RELOAD ============================
            // waitFor requires VISIBILITY -- the minimised `about` surface is display:none, so we
            // wait on its PRESENCE in the DOM (the WM adopted it) via a script poll, not waitFor.
            $browser->refresh()
                ->waitFor('[data-window-id="hello"] .sx-window', 10)
                ->waitFor('[data-window-id="notes"] .sx-window', 10)
                ->waitFor('[data-window-id="'.$appearId.'"] .sx-window', 10)
                ->waitUntil("!!document.querySelector('[data-window-id=\"{$aboutId}\"]')", 10)
                ->pause(400); // let the WM ctor finish its boot-restore + focus

            // --- assert each window came back at its saved geometry ---

            // hello: restored at its MOVED offset (NOT the cascade origin), and ACTIVE (the
            // raise restored the focus). Stacking/focus is asserted via data-sx-active, never z.
            $helloBack = $this->geometry($browser, 'hello');
            $this->assertEqualsWithDelta($helloMoved['x'], $helloBack['x'], 2, 'hello did not restore its x offset on reload');
            $this->assertEqualsWithDelta($helloMoved['y'], $helloBack['y'], 2, 'hello did not restore its y offset on reload');
            $this->assertStringNotContainsString('40px, 40px', $helloBack['t'], 'hello re-cascaded instead of restoring its saved position');
            $this->assertEquals('true', $helloBack['active'], 'the raised window is not active after reload (stacking/focus not restored)');

            // notes: restored at its RESIZED size.
            $notesBack = $this->geometry($browser, 'notes');
            $this->assertEquals('true', $notesBack['sized'], 'notes lost its sized flag on reload');
            $this->assertEqualsWithDelta($notesResized['w'], $notesBack['w'], 2, 'notes did not restore its width on reload');
            $this->assertEqualsWithDelta($notesResized['h'], $notesBack['h'], 2, 'notes did not restore its height on reload');

            // appearance: restored MAXIMISED, filling the work area (fresh against the viewport).
            $appearBack = $this->geometry($browser, $appearId);
            $this->assertEquals('true', $appearBack['max'], 'appearance did not restore maximised on reload');
            $this->assertEqualsWithDelta($appearBack['mountW'], $appearBack['w'], 2, 'a restored-maximised window does not fill the work-area width');
            $this->assertStringContainsString('translate3d(0px, 34px', $appearBack['t'], 'a restored-maximised window is not pinned below the top panel');

            // about: restored MINIMISED (hidden, in the panel only).
            $aboutBack = $this->geometry($browser, $aboutId);
            $this->assertEquals('true', $aboutBack['min'], 'about did not restore minimised on reload');
            $this->assertEquals('none', $aboutBack['display'], 'a restored-minimised surface is not hidden');

            // The record shot of the restored-on-reload desktop.
            $browser->screenshot('window-geometry-restored-on-reload');

            // --- B1: the maximise-restore round-trip ACROSS reload ---
            // Restore the (reloaded) maximised appearance -> it must return to its PERSISTED
            // restore rect (its pre-max cascade rect), NOT the maximised work-area size. This
            // proves the WM seeded the pre-max stash from the PERSISTED rect on boot, not from
            // the live maximised surface (the recon's gotcha #3 -- the client stash is lost on
            // reload; the persisted restore rect replaces it).
            $browser->click('[data-window-id="'.$appearId.'"] [data-sx-control="restore"]')->pause(220);
            $appearRestored = $this->geometry($browser, $appearId);
            $this->assertEquals('false', $appearRestored['max'], 'restore did not clear the maximised flag');
            // It returns to the PERSISTED restore rect -- the explicit sized w/h it had pre-max,
            // NOT the maximised work-area fill. This only holds if the WM seeded the pre-max stash
            // from the PERSISTED rect on boot (the live client stash was lost on reload).
            $this->assertEquals('true', $appearRestored['sized'], 'the restored window lost its sized flag (B1 across reload)');
            $this->assertLessThan(
                $appearBack['w'],
                $appearRestored['w'],
                'restore returned the maximised size, not the persisted RESTORE rect (B1 across reload failed)'
            );
            $this->assertEqualsWithDelta(
                $appearPreMax['w'],
                $appearRestored['w'],
                2,
                'restore did not return to the persisted RESTORE width (B1 across reload failed)'
            );
            $this->assertEqualsWithDelta(
                $appearPreMax['h'],
                $appearRestored['h'],
                2,
                'restore did not return to the persisted RESTORE height (B1 across reload failed)'
            );
            $this->assertEqualsWithDelta(
                $appearPreMax['x'],
                $appearRestored['x'],
                2,
                'restore did not land at the persisted pre-max x (B1 across reload failed)'
            );
            $this->assertEqualsWithDelta(
                $appearPreMax['y'],
                $appearRestored['y'],
                2,
                'restore did not land at the persisted pre-max y (B1 across reload failed)'
            );
        });
    }

    // Test isolation + the fresh-boot cascade (the other half of the contract): a user with NO
    // saved geometry boots CASCADED (40,40 origin stepping down-right), NOT at 0,0 and NOT
    // restored. DuskTestCase::setUp truncates system_x_open_windows every method, so the
    // geometry columns reset with it -- the seeded defaults come back at NULL geometry, so the
    // WM cascades. This guards against a prior method's geometry leaking in (it can't -- the
    // truncate covers the geometry columns since they ARE columns on that row).
    public function test_a_user_with_no_saved_geometry_still_boots_cascaded_not_restored(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="notes"] .sx-window')
                ->pause(200);

            // hello is the first cascade window -> the cascade ORIGIN (40,40), the standing
            // default. If geometry had leaked from another method it would be elsewhere.
            $hello = $this->geometry($browser, 'hello');
            $this->assertStringContainsString('40px, 40px', $hello['t'], 'a fresh-boot window did not cascade from the (40,40) origin -- geometry leaked or the cascade broke');
            $this->assertEquals('false', $hello['sized'], 'a fresh-boot window is unexpectedly sized');
            $this->assertEquals('false', $hello['max'], 'a fresh-boot window is unexpectedly maximised');

            // notes is the SECOND cascade window -> stepped down-right by one CASCADE_STEP (28).
            $notes = $this->geometry($browser, 'notes');
            $this->assertStringContainsString('68px, 68px', $notes['t'], 'the second fresh-boot window did not cascade-step from the first');
        });
    }
}

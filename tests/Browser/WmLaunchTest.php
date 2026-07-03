<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class WmLaunchTest extends DuskTestCase
{
    // Task 8 (Plan 5a, D7): a launch opens a window at runtime end-to-end -- POST
    // /system-x/wm/launch, the server mints/returns the window + its initial tree, the
    // client mints-or-focuses a surface and raises it. The polished launcher trigger is
    // Task 10; here we drive launch() directly off the live display server (window.sx).
    //
    // The demo user boots with the static pair (hello + notes) ALREADY open, and those
    // are the only two registered apps -- so a launch of either is the SINGLETON path
    // (S4): the server returns the EXISTING window, and the client raises it WITHOUT
    // minting a duplicate. That is the real end-to-end launch round-trip the browser can
    // exercise without Task 10. The genuinely-new-ULID mint is proven by the feature +
    // Vitest units (a fresh ULID surface), which can't collide with a seeded slug.

    public function test_launching_an_open_app_round_trips_and_focuses_it_without_duplicating(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="notes"] .sx-window');

            // Raise hello so notes is NOT the active window before we launch it -- then the
            // launch's focus change is observable.
            $browser->script(
                "document.querySelector('[data-window-id=\"hello\"]')"
                .".dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerId: 1 }));"
            );
            $browser->pause(80);
            $activeBefore = $browser->script(
                "return document.querySelector('[data-window-id=\"notes\"]').dataset.sxActive;"
            )[0];
            $this->assertEquals('false', $activeBefore, 'notes should be inactive before its launch');

            // Drive a real launch end-to-end: POST -> mint-or-focus -> raise. window.sx is
            // the live display server (the hook the Task 10 trigger will call).
            $browser->script('window.sx.launch("notes");');

            // Singleton (S4): notes is already open, so NO second surface is minted -- the
            // existing notes window is raised + focused. Wait for the focus to flip.
            $browser->waitUsing(10, 100, function () use ($browser): bool {
                return $browser->script(
                    "return document.querySelector('[data-window-id=\"notes\"]').dataset.sxActive === 'true';"
                )[0];
            });

            $state = $browser->script(<<<'JS'
                const surfaces = document.querySelectorAll('#sx-desktop [data-window-id]');
                const notes = document.querySelector('[data-window-id="notes"]');
                return JSON.stringify({
                    count: surfaces.length,
                    notesCount: document.querySelectorAll('[data-window-id="notes"]').length,
                    active: notes.dataset.sxActive,
                    hasField: !!notes.querySelector('[data-sx-id="message-field"]'),
                });
            JS)[0];
            $state = json_decode($state, true);

            // STILL exactly two surfaces -- the singleton launch did NOT mint a duplicate --
            // notes is the focused window and still renders its tree (untouched by the morph).
            $this->assertEquals(2, $state['count'], 'a singleton launch minted a duplicate surface');
            $this->assertEquals(1, $state['notesCount'], 'notes was duplicated');
            $this->assertEquals('true', $state['active'], 'the launched (existing) notes window was not focused');
            $this->assertTrue($state['hasField'], 'notes lost its tree after the launch');

            $browser->screenshot('wm-launch-singleton-focus');
        });
    }

    // Plan 5b, D5 (was the Task 10 open stub, now the LAUNCHER): the launcher overlay drives
    // the WHOLE open/close/relaunch lifecycle through the panel's system-x button + an app
    // tile (not window.sx.launch directly). This is the proof the launcher is wired AND the
    // genuinely-new-window proof the Task 8 singleton guard prevented: with notes OPEN,
    // picking it from the launcher FOCUSES it (no duplicate); after CLOSING notes, picking it
    // from the launcher again mints a FRESH ULID window. The launcher just replaces the stub
    // as the open trigger -- the focus-if-open-else-launch behaviour is unchanged.
    public function test_the_launcher_focuses_an_open_app_then_mints_a_fresh_window_after_close(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="notes"] .sx-window');

            // Raise hello so notes is inactive -- then "Open notes" focusing it is observable.
            $browser->script(
                "document.querySelector('[data-window-id=\"hello\"]')"
                .".dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerId: 1 }));"
            );
            $browser->pause(80);
            $this->assertEquals(
                'false',
                $browser->script("return document.querySelector('[data-window-id=\"notes\"]').dataset.sxActive;")[0],
                'notes should be inactive before the launcher focuses it'
            );

            // Open the launcher (the panel's system-x button) + pick notes. notes is OPEN ->
            // the singleton path just FOCUSES the existing surface, no duplicate (the openApp
            // focus-if-open branch the launcher reuses). Screenshot the open overlay.
            $this->openViaLauncher($browser, 'notes', 'wm-launch-launcher-open');
            $browser->waitUsing(10, 100, fn (): bool => $browser->script(
                "return document.querySelector('[data-window-id=\"notes\"]').dataset.sxActive === 'true';"
            )[0]);

            $afterFocus = json_decode($browser->script(<<<'JS'
                return JSON.stringify({
                    total: document.querySelectorAll('#sx-desktop [data-window-id]').length,
                    notesCount: document.querySelectorAll('[data-window-id="notes"]').length,
                });
            JS)[0], true);
            $this->assertEquals(2, $afterFocus['total'], 'the launcher minted a duplicate instead of focusing');
            $this->assertEquals(1, $afterFocus['notesCount'], 'notes was duplicated by the launcher');

            // Now CLOSE notes via its close control -- the surface vanishes and the open-row +
            // bag are reaped server-side, so the next open is NOT a singleton hit.
            $browser->script(
                "document.querySelector('[data-window-id=\"notes\"] [data-sx-control=\"close\"]')"
                .".dispatchEvent(new MouseEvent('click', { bubbles: true }));"
            );
            $browser->waitUntilMissing('[data-window-id="notes"]', 10);

            // Open the launcher + pick notes AGAIN. notes is gone, so the launcher LAUNCHES a
            // fresh window -- a brand-new ULID surface (26 chars), NOT the closed slug-as-id
            // 'notes'. After close, notes isn't open, so openApp finds no surface and launch
            // mints a new ULID (unchanged behaviour -- the launcher only replaces the trigger).
            $this->openViaLauncher($browser, 'notes');
            $browser->waitUsing(10, 100, fn (): bool => $browser->script(
                "return !!document.querySelector('.sx-window-surface[data-app=\"notes\"]');"
            )[0]);

            $reopened = json_decode($browser->script(<<<'JS'
                const s = document.querySelector('.sx-window-surface[data-app="notes"]');
                return JSON.stringify({
                    id: s ? s.dataset.windowId : null,
                    active: s ? s.dataset.sxActive : null,
                    hasField: s ? !!s.querySelector('[data-sx-id="message-field"]') : false,
                });
            JS)[0], true);

            $this->assertNotNull($reopened['id'], 'the launcher did not mint a surface on reopen');
            $this->assertNotEquals('notes', $reopened['id'], 'the reopen re-used the closed slug id (should be a fresh ULID)');
            $this->assertEquals(26, strlen($reopened['id']), 'the reopened window is not a fresh ULID');
            $this->assertEquals('true', $reopened['active'], 'the freshly opened notes window was not focused');
            $this->assertTrue($reopened['hasField'], 'the reopened notes window did not render its tree');

            $browser->screenshot('wm-launch-launcher-reopened-fresh');
        });
    }

    // B4: a LAUNCHED (ULID) window must survive a real socket drop + reconnect. On
    // reconnect the display server's bindConnectionState fires resyncAll, which re-fetches
    // EVERY adopted surface -- including launched ULIDs. Before B4 a launched window's
    // resync GET 404'd (the open-set didn't carry it), so the window vanished on reconnect.
    // The Vitest unit (window-routing.test.js) proves resyncAll repaints a ULID surface;
    // this is the browser-level end-to-end proof over a live Reverb socket.
    public function test_a_launched_window_survives_a_socket_drop_and_reconnect(): void
    {
        $this->browse(function (Browser $browser): void {
            $desktopId = $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="notes"] .sx-window')
                ->attribute('#sx-desktop', 'data-desktop-id');
            $this->waitForChannel($browser, $desktopId);

            // Close + re-open notes so it is a freshly LAUNCHED (ULID) window, not the
            // static slug-id pair -- the launch path is exactly what B4 fixes.
            $browser->script(
                "document.querySelector('[data-window-id=\"notes\"] [data-sx-control=\"close\"]')"
                .".dispatchEvent(new MouseEvent('click', { bubbles: true }));"
            );
            $browser->waitUntilMissing('[data-window-id="notes"]', 10);

            // Re-open notes via the launcher (the panel's system-x button + the notes tile).
            // notes was just closed, so this mints a FRESH ULID -- the same mint-fresh-after-
            // close the reconnect then has to resync (the launcher only replaces the trigger).
            $this->openViaLauncher($browser, 'notes');
            $browser->waitUsing(10, 100, fn (): bool => $browser->script(
                "return !!document.querySelector('.sx-window-surface[data-app=\"notes\"]');"
            )[0]);

            // The launched window's fresh ULID -- prove THIS surface survives the round-trip.
            $launchedId = $browser->script(
                "return document.querySelector('.sx-window-surface[data-app=\"notes\"]').dataset.windowId;"
            )[0];
            $this->assertEquals(26, strlen($launchedId), 'reopen did not mint a fresh ULID window');

            // Strip the launched window's TREE so the resync repaint is observable: if
            // resyncAll skips this ULID (the pre-B4 404 path) the field stays gone; if it
            // re-fetches + repaints it, the field comes back. Without this the surface div
            // alone would survive a no-op reconnect and prove nothing.
            $browser->script(
                "const w = document.querySelector('[data-window-id=\"{$launchedId}\"] .sx-window'); if (w) w.remove();"
            );

            // Drive a real socket drop + reconnect through pusher's OWN state machine -- the
            // exact path a dropped Reverb socket hits (the same mechanism ReconnectResyncTest
            // uses). bindConnectionState only resyncs on a GENUINE re-connect (it gates on
            // hasConnected so boot's initial fetch isn't doubled). bindConnectionState binds
            // AFTER the channel subscribes, so in headless it can miss the live socket's first
            // 'connected' edge -- hasConnected can still be false here. So we emit that initial
            // 'connected' ourselves first (standing in for the connect the handler bound too
            // late to see), THEN the real drop -> reconnect, which now fires resyncAll. That
            // loops the LIVE window map -- launched ULIDs included (B4) -- not just the static
            // pair. Emitting the transitions (vs Echo.disconnect()/connect()) drives the resync
            // deterministically instead of racing a live re-handshake under load.
            $browser->script(<<<'JS'
                const c = window.Echo.connector.pusher.connection;
                c.emit('state_change', { previous: 'connecting', current: 'connected' });
            JS);
            $browser->script(<<<'JS'
                const c = window.Echo.connector.pusher.connection;
                c.emit('state_change', { previous: 'connected', current: 'unavailable' });
            JS);
            $browser->waitFor('.sx-reconnecting');
            $browser->script(<<<'JS'
                const c = window.Echo.connector.pusher.connection;
                c.emit('state_change', { previous: 'unavailable', current: 'connected' });
            JS);
            $browser->waitUntilMissing('.sx-reconnecting', 10);

            // The launched window survives the reconnect resync (B4 -- its app resolved from
            // the open-set, not a 404) and is REPAINTED with its tree (the stripped field is back).
            $browser->waitFor("[data-window-id=\"{$launchedId}\"] .sx-window");
            $browser->waitFor("[data-window-id=\"{$launchedId}\"] [data-sx-id=\"message-field\"]");
            $stillThere = $browser->script(
                "const s = document.querySelector('[data-window-id=\"{$launchedId}\"]'); "
                .'return s ? !!s.querySelector(\'[data-sx-id="message-field"]\') : false;'
            )[0];
            $this->assertTrue($stillThere, 'the launched window did not survive the reconnect resync (B4)');

            $browser->screenshot('wm-launch-survives-reconnect');
        });
    }

    // Drive the launcher (Plan 5b, D5): click the panel's system-x button to open the overlay,
    // wait for the app's tile, click it. The launcher reuses openApp (focus-if-open-else-
    // launch) + closes itself. Body-mounted (B4), so the panel button + the tile both live
    // outside #sx-desktop -- a real ->click() resolves them.
    private function openViaLauncher(Browser $browser, string $slug, ?string $screenshot = null): void
    {
        $browser->click('[data-sx-launcher]')
            ->waitFor("[data-sx-launch=\"{$slug}\"]");
        if ($screenshot !== null) {
            $browser->screenshot($screenshot); // the open launcher overlay (the app grid)
        }
        $browser->click("[data-sx-launch=\"{$slug}\"]")
            ->waitUntilMissing('[data-sx-launch]', 10);
    }
}

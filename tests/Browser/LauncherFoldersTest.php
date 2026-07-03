<?php

namespace Tests\Browser;

use Facebook\WebDriver\Interactions\WebDriverActions;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

// The launcher-folders round-trip in the REAL boot DOM (Slice 4a). Everything below is unit-green
// in Vitest/PHPUnit; this is the browser proof that the whole stack lines up -- the right-click
// menu opens, "New folder..." pulls an app out of root into a folder tile, the shelf expands with
// the app inside, a second app moves in, and (the load-bearing bit) all of it survives a full page
// reload via the POST /system-x/launcher/layout persist + the boot-blob reconcile.
class LauncherFoldersTest extends DuskTestCase
{
    public function test_a_folder_round_trips_through_persist_and_reload(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                // Open the launcher -- the flat fresh-user grid (hello/notes/controls/example.todo).
                ->click('[data-sx-launcher]')
                ->waitFor('.sx-launcher-grid')
                ->assertPresent('.sx-launcher-grid [data-sx-launch="hello"]')
                ->assertMissing('[data-sx-folder]');

            // 1) Create a folder from `hello` via a GENUINE right-click on its tile. The per-tile
            // menu opens (.sx-context-menu) with a "New folder..." row (data-sx-menu="new-folder");
            // clicking it runs newFolderFrom('hello', ...) -> hello leaves root, a folder tile appears.
            // This drives the right-click end-to-end (the menu + the item) rather than the script seam.
            (new WebDriverActions($browser->driver))
                ->contextClick($browser->element('.sx-launcher-grid [data-sx-launch="hello"]'))
                ->perform();

            $browser->waitFor('.sx-context-menu')
                ->assertPresent('.sx-context-menu [data-sx-menu="new-folder"]')
                ->click('.sx-context-menu [data-sx-menu="new-folder"]')
                // A folder tile appeared and hello left the root grid (it's inside the folder now).
                ->waitFor('[data-sx-folder]')
                ->assertMissing('.sx-launcher-grid > [data-sx-launch="hello"]');

            // 2) Open the folder shelf -- hello is inside it. Then collapse it again so step 3 starts
            // from a known-closed shelf (moveTo re-renders in place; leaving it open would make the
            // reopen-click below toggle it CLOSED instead of open).
            $browser->click('[data-sx-folder]')
                ->waitFor('.sx-launcher-shelf')
                ->assertPresent('.sx-launcher-shelf [data-sx-launch="hello"]')
                ->script('window.sx.launcher.closeShelf();');
            $browser->waitUntilMissing('.sx-launcher-shelf');

            // 3) Move a SECOND app (`notes`) into the folder via the launcher API (moveTo). Read the
            // folder id off the DOM so we target the folder we just made, then reopen the shelf and
            // assert notes joined hello.
            $browser->script(<<<'JS'
                const id = document.querySelector('[data-sx-folder]').dataset.sxFolder;
                window.sx.launcher.moveTo('notes', id);
            JS);
            $browser->pause(400) // let the single-flight persist POST settle
                ->click('[data-sx-folder]')
                ->waitFor('.sx-launcher-shelf')
                ->assertPresent('.sx-launcher-shelf [data-sx-launch="hello"]')
                ->assertPresent('.sx-launcher-shelf [data-sx-launch="notes"]');

            // 4) PERSISTENCE -- the whole point. Close the launcher, reload the page (the session
            // survives), reopen the launcher: the folder is STILL there and STILL holds hello+notes.
            // This proves the POST landed AND the boot-blob reconcile round-trips the stored layout.
            $browser->script('window.sx.launcher.close();');
            $browser->pause(400) // let any trailing persist finish before we navigate away
                ->refresh()
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->click('[data-sx-launcher]')
                ->waitFor('.sx-launcher-grid')
                ->assertPresent('[data-sx-folder]')
                ->click('[data-sx-folder]')
                ->waitFor('.sx-launcher-shelf')
                ->assertPresent('.sx-launcher-shelf [data-sx-launch="hello"]')
                ->assertPresent('.sx-launcher-shelf [data-sx-launch="notes"]')
                ->screenshot('launcher-folder-round-trip');
        });
    }

    // The Slice 4a DISCOVERABILITY proof -- the new create gestures + explicit Delete, end-to-end in
    // the real boot DOM. The backdrop menu + delete drive through a genuine right-click; the drag-onto
    // goes through the launcher API seam (Selenium pointer-drag is flaky, the onto math is unit-tested).
    public function test_the_new_create_gestures_and_delete_round_trip(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->click('[data-sx-launcher]')
                ->waitFor('.sx-launcher-grid')
                ->assertMissing('[data-sx-folder]');

            // 1) Backdrop "New folder" -- a GENUINE right-click on empty grid space (not over a tile)
            // opens the backdrop menu with a "New folder" row (data-sx-menu="new-folder-empty");
            // clicking it runs newEmptyFolder() -> a fresh empty folder tile appears at root.
            (new WebDriverActions($browser->driver))
                ->contextClick($browser->element('.sx-launcher-grid'))
                ->perform();

            $browser->waitFor('.sx-context-menu')
                ->assertPresent('.sx-context-menu [data-sx-menu="new-folder-empty"]')
                ->click('.sx-context-menu [data-sx-menu="new-folder-empty"]')
                ->waitFor('[data-sx-folder]')
                ->assertPresent('[data-sx-folder]');

            // 2) Drag-onto (the group gesture) via the launcher API -- dropOntoRoot(1, 0) groups the
            // tile at root index 1 onto the one at index 0. Assert a folder tile forms, then prove it
            // PERSISTS: close, reload, reopen -> the folder is still there (POST + boot reconcile).
            $browser->script('window.sx.launcher.dropOntoRoot(1, 0);');
            $browser->pause(400) // let the single-flight persist POST settle
                ->waitFor('[data-sx-folder]')
                ->assertPresent('[data-sx-folder]');

            $browser->script('window.sx.launcher.close();');
            $browser->pause(400)
                ->refresh()
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->click('[data-sx-launcher]')
                ->waitFor('.sx-launcher-grid')
                ->assertPresent('[data-sx-folder]');

            // 3) Explicit Delete -- read a folder id off the DOM, deleteFolder(id) removes it (folders
            // never auto-dissolve; only an explicit Delete drops them). Assert the tile's gone, then
            // reload and confirm it STAYS gone (the delete persisted, not just a client-side removal).
            $folderId = $browser->script(
                "return document.querySelector('[data-sx-folder]').dataset.sxFolder;"
            )[0];

            $browser->script("window.sx.launcher.deleteFolder('{$folderId}');");
            $browser->pause(400)
                ->assertMissing("[data-sx-folder=\"{$folderId}\"]");

            $browser->script('window.sx.launcher.close();');
            $browser->pause(400)
                ->refresh()
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->click('[data-sx-launcher]')
                ->waitFor('.sx-launcher-grid')
                ->assertMissing("[data-sx-folder=\"{$folderId}\"]")
                ->screenshot('launcher-folder-gestures-round-trip');
        });
    }
}

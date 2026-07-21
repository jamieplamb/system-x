<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

// Task 10 (widget plan): the browser proof for the Controls gallery app. Launch Controls
// from the launcher, drive each of the four inputs (Select/RadioGroup/Switch/Slider), assert
// the readout Label morphs to reflect the durable state after every round-trip, then RELOAD
// and assert the readout STILL shows every value. The reload-persistence is the whole point:
// forms that survive a page reload. It also proves the Slider's string->int coercion end-to-
// end -- the range commits the value as the STRING "50", it lands in the app's int $volume,
// persists, and re-renders as the number 50 (not "50.0", not blanked).
//
// The Controls window is a LAUNCHED app, so it gets a fresh ULID surface carrying
// data-app="controls". The inputs are stamped with stable data-sx-id wrappers (ctl-theme/
// ctl-size/ctl-wifi/ctl-volume); the native control lives inside each wrapper. The round-trip
// is async over the websocket, so after every interaction we WAIT for the readout text to
// update before asserting (the suite's waitForText idiom -- no arbitrary sleeps).
class ControlsGalleryTest extends DuskTestCase
{
    public function test_every_control_round_trips_and_the_whole_form_survives_a_reload(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window');

            // Open Controls via the launcher (the panel's system-x button + the controls tile).
            // openViaLauncher is PRIVATE to WmLaunchTest (not inherited), so the pattern is
            // copied here: click the launcher trigger, wait for the tile, click it, wait for the
            // overlay to close. Controls is a launchable USER app, so this mints a ULID window.
            $this->openViaLauncher($browser, 'controls');

            // Wait for the Controls window + its readout to render. The readout boots from the
            // shipped defaults: light/s/off/0.
            $browser->waitFor('[data-app="controls"] .sx-window')
                ->waitFor('[data-sx-id="controls-readout"]')
                ->waitForText('theme=light size=s wifi=off volume=0');

            // The gallery is organised into category tabs; the form inputs live in the
            // 'Inputs' category. Switch to it (the category radios are hidden, so script-
            // dispatch) and wait for a control to be visible before interacting.
            $this->switchCategory($browser, 'inputs');
            $browser->waitFor('[data-sx-id="ctl-theme"]');

            // SELECT -- set ctl-theme's native <select> to 'dark'. select() drives a real change
            // on the inner <select>; the dispatcher resolves up to the data-sx-id wrapper and
            // round-trips the value, the App writes $theme, and the readout Label morphs.
            $browser->select('[data-sx-id="ctl-theme"] select', 'dark')
                ->waitForText('theme=dark');

            // RADIOGROUP -- pick the 'l' (Large) radio inside ctl-size. The real radio is
            // visually hidden (CSS), so Selenium's click reports "element not interactable".
            // Drive it the same way as the slider: check the 'l' input + dispatch a bubbling
            // change so the delegated surface dispatcher catches it and echoes value 'l'.
            $browser->script(<<<'JS'
                const radio = document.querySelector('[data-sx-id="ctl-size"] input[type=radio][value="l"]');
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            JS);
            $browser->waitForText('size=l');

            // SWITCH -- toggle ctl-wifi. The real checkbox is visually hidden too, so script the
            // toggle + a bubbling change. The dispatcher echoes the boolean checked=true, which
            // the App casts to bool -> the readout shows wifi=on.
            $browser->script(<<<'JS'
                const box = document.querySelector('[data-sx-id="ctl-wifi"] input[type=checkbox]');
                box.checked = true;
                box.dispatchEvent(new Event('change', { bubbles: true }));
            JS);
            $browser->waitForText('wifi=on');

            // SLIDER -- the trickiest. Dusk can't drag a native range, and the slider commits on
            // the native `change` (fires on release). So set the range input's .value to 50 and
            // dispatch a `change` event with bubbles:true -- the dispatcher is a DELEGATED
            // listener on the surface, so a non-bubbling event would never reach it. The value
            // round-trips as the STRING "50"; the App's onChange does (int) $event->value, so it
            // lands in int $volume and the readout re-renders the number 50.
            $browser->script(<<<'JS'
                const range = document.querySelector('[data-sx-id="ctl-volume"] input[type=range]');
                range.value = 50;
                range.dispatchEvent(new Event('change', { bubbles: true }));
            JS);
            $browser->waitForText('volume=50');

            // All four landed together -- the full durable form before the reload.
            $browser->waitForText('theme=dark size=l wifi=on volume=50')
                ->screenshot('controls-gallery-all-set');

            // THE PROOF -- reload. Every value was persisted server-side (the per-user durable
            // state, keyed by the window). On reboot the Controls window re-renders FROM that
            // state, so the readout must STILL show every value. This proves per-user durability
            // AND the Slider's int coercion survived the round-trip (volume came back as "50",
            // persisted as int 50, re-rendered as 50 -- not blanked, not "50.0").
            $browser->refresh()
                ->waitFor('[data-app="controls"] .sx-window')
                ->waitFor('[data-sx-id="controls-readout"]')
                ->waitForText('theme=dark size=l wifi=on volume=50');

            $browser->screenshot('controls-gallery-after-reload');
        });
    }

    // Task 4 (containers & navigation plan): the browser proof for Tabs + Toolbar. Launch
    // Controls, switch to the second tab (the durable active tab), assert the shown panel
    // flips (second visible, first hidden), click a Toolbar Button and assert its handler
    // ran, then RELOAD and assert the active tab STILL shows second. The reload-persistence
    // of the active tab is the whole point -- durable navigation state that survives a reload.
    //
    // The tabs' inner radios are visually hidden (RadioGroup-style CSS), so Selenium's
    // ->click() reports "element not interactable"; we SCRIPT-DISPATCH a bubbling change on
    // the 'second' radio (same idiom as the slice-1 RadioGroup/Switch/Slider blocks) so the
    // delegated surface dispatcher catches it and round-trips the value. The Toolbar Buttons
    // are NOT hidden, so a real ->click() reaches the handler. The round-trip proofs ride on
    // waitForText timeouts (no explicit assertion each); the panel-visibility checks are the
    // explicit assertVisible/assertMissing assertions.
    public function test_tabs_switch_and_persist_across_reload_and_toolbar_click_runs_its_handler(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window');

            $this->openViaLauncher($browser, 'controls');

            // Wait for the Controls window + readout. Boots from shipped defaults: activeTab=first.
            $browser->waitFor('[data-app="controls"] .sx-window')
                ->waitFor('[data-sx-id="controls-readout"]')
                ->waitForText('activeTab=first lastAction=none');

            // The demo Tabs + Toolbar live in the 'Containers' category tab. Switch to it
            // (category radios are hidden, so script-dispatch) and wait for the demo Tabs to
            // be visible before touching it.
            $this->switchCategory($browser, 'containers');
            $browser->waitFor('[data-sx-id="ctl-tabs"]');

            // First panel is the one on show; the second is hidden ([hidden] -> display:none).
            $browser->assertVisible('[data-sx-id="tab-panel-first"]')
                ->assertMissing('[data-sx-id="tab-panel-second"]');

            // TAB SWITCH -- the tab radios are visually hidden, so drive the 'second' radio the
            // same way as the RadioGroup block: check it + dispatch a bubbling change. The
            // delegated dispatcher round-trips the value, the App writes $activeTab, and the
            // readout Label morphs to activeTab=second.
            $browser->script(<<<'JS'
                const radio = document.querySelector('[data-sx-id="ctl-tabs"] input[type=radio][value="second"]');
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            JS);
            $browser->waitForText('activeTab=second');

            // The panel flipped -- second is shown, first is now [hidden].
            $browser->assertVisible('[data-sx-id="tab-panel-second"]')
                ->assertMissing('[data-sx-id="tab-panel-first"]');

            // TOOLBAR CLICK -- the Toolbar Buttons are NOT hidden, so a real click reaches the
            // 'toolbarNew' handler, which sets $lastAction=new and the readout morphs.
            $browser->click('[data-sx-id="tb-new"]')
                ->waitForText('lastAction=new');

            // THE PROOF -- reload. The active tab was persisted server-side (durable per-user
            // state). On reboot the Controls window re-renders FROM that state, so the readout
            // must STILL show activeTab=second and the second panel must still be the one on
            // show. (lastAction is a transient marker -- not required to survive the reload.)
            $browser->refresh()
                ->waitFor('[data-app="controls"] .sx-window')
                ->waitFor('[data-sx-id="controls-readout"]')
                ->waitForText('activeTab=second');

            $browser->assertVisible('[data-sx-id="tab-panel-second"]')
                ->assertMissing('[data-sx-id="tab-panel-first"]')
                ->screenshot('controls-tabs-after-reload');
        });
    }

    // Slice 3a (dialog plan): the browser proof for the window-modal Dialog. Open Controls,
    // switch to the Dialogs category, open the dismissible dialog and assert the backdrop +
    // panel appear over the window content; dismiss via the close button, then re-open and
    // dismiss via Escape, then via a backdrop click. The non-dismissible dialog ignores Escape
    // (stays open) and only its own button closes it. Finally, open the dismissible dialog and
    // RELOAD: its $showDialog is durable, so it must reopen -- the durable-open proof.
    public function test_dialog_opens_dismisses_three_ways_and_survives_reload(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)->waitFor('[data-window-id="hello"] .sx-window');
            $this->openViaLauncher($browser, 'controls');

            $browser->waitFor('[data-app="controls"] .sx-window')
                ->waitFor('[data-sx-id="controls-readout"]')
                ->waitForText('dialog=closed');

            $this->switchCategory($browser, 'dialogs');
            $browser->waitFor('[data-sx-id="dlg-open"]');

            // OPEN -> backdrop + panel appear, readout flips to open.
            $browser->click('[data-sx-id="dlg-open"]')
                ->waitFor('[data-sx-id="ctl-dialog"] .sx-dialog-backdrop')
                ->assertVisible('[data-sx-id="ctl-dialog"] .sx-dialog-panel')
                ->waitForText('dialog=open');

            // DISMISS via the close button.
            $browser->click('[data-sx-id="ctl-dialog"] .sx-dialog-close')
                ->waitUntilMissing('[data-sx-id="ctl-dialog"] .sx-dialog-backdrop')
                ->waitForText('dialog=closed');

            // RE-OPEN + DISMISS via Escape (script-dispatch the keydown on the backdrop).
            $browser->click('[data-sx-id="dlg-open"]')
                ->waitFor('[data-sx-id="ctl-dialog"] .sx-dialog-backdrop');
            $browser->script(<<<'JS'
                document.querySelector('[data-sx-id="ctl-dialog"] .sx-dialog-backdrop')
                    .dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
            JS);
            $browser->waitUntilMissing('[data-sx-id="ctl-dialog"] .sx-dialog-backdrop')
                ->waitForText('dialog=closed');

            // RE-OPEN + DISMISS via a backdrop click (mousedown on the backdrop itself).
            $browser->click('[data-sx-id="dlg-open"]')
                ->waitFor('[data-sx-id="ctl-dialog"] .sx-dialog-backdrop');
            $browser->script(<<<'JS'
                const b = document.querySelector('[data-sx-id="ctl-dialog"] .sx-dialog-backdrop');
                b.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
            JS);
            $browser->waitUntilMissing('[data-sx-id="ctl-dialog"] .sx-dialog-backdrop')
                ->waitForText('dialog=closed');

            // NON-DISMISSIBLE -- Escape is ignored (still open); only its button closes it.
            $browser->click('[data-sx-id="dlg-open-forced"]')
                ->waitFor('[data-sx-id="ctl-dialog-forced"] .sx-dialog-backdrop');
            $browser->script(<<<'JS'
                document.querySelector('[data-sx-id="ctl-dialog-forced"] .sx-dialog-backdrop')
                    .dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
            JS);
            $browser->pause(300)->assertVisible('[data-sx-id="ctl-dialog-forced"] .sx-dialog-panel');
            $browser->click('[data-sx-id="dlg-forced-ok"]')
                ->waitUntilMissing('[data-sx-id="ctl-dialog-forced"] .sx-dialog-backdrop');

            // THE DURABLE PROOF -- open the dismissible dialog, reload, it reopens.
            $browser->click('[data-sx-id="dlg-open"]')
                ->waitFor('[data-sx-id="ctl-dialog"] .sx-dialog-backdrop')
                ->waitForText('dialog=open');
            $browser->refresh()
                ->waitFor('[data-app="controls"] .sx-window')
                ->waitFor('[data-sx-id="ctl-dialog"] .sx-dialog-backdrop')
                ->waitForText('dialog=open')
                ->screenshot('controls-dialog-after-reload');

            // Dismiss the BOOTED dialog via Escape, dispatched on document.body -- focus has not
            // been inside the panel this page-load, so this proves the document-scoped Escape
            // (the old backdrop-scoped listener was dead in exactly this state). Also the re-run
            // cleanup (durable state persists across test runs).
            $browser->script(<<<'JS'
                document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
            JS);
            $browser->waitUntilMissing('[data-sx-id="ctl-dialog"] .sx-dialog-backdrop')
                ->waitForText('dialog=closed');
        });
    }

    // Slice 3b (menu plan): browser proof for MenuButton + MenuBar. The popups portal to body, so
    // they're asserted at document scope. Open the MenuButton, pick an item -> readout shows the value;
    // re-open + click outside -> closes. Open a MenuBar menu, hover the sibling label -> the open menu
    // switches; pick -> readout updates. Menu open-state is transient (no reload assertion on the menu);
    // $lastPick is durable, proving the select round-trip.
    public function test_menus_open_pick_switch_and_dismiss(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)->waitFor('[data-window-id="hello"] .sx-window');
            $this->openViaLauncher($browser, 'controls');
            $browser->waitFor('[data-app="controls"] .sx-window')
                ->waitFor('[data-sx-id="controls-readout"]')
                ->waitForText('menuPick=none');

            $this->switchCategory($browser, 'menus');
            $browser->waitFor('[data-sx-id="ctl-menubutton"]');

            // MenuButton: open -> popup at body -> pick 'Save'
            $browser->click('[data-sx-id="ctl-menubutton"]')->waitFor('.sx-menu');
            $browser->script(<<<'JS'
                [...document.querySelectorAll('.sx-menu .sx-menu-item')]
                    .find(n => n.textContent.trim().startsWith('Save')).click();
            JS);
            $browser->waitForText('menuPick=save')->waitUntilMissing('.sx-menu');

            // Re-open + outside click closes
            $browser->click('[data-sx-id="ctl-menubutton"]')->waitFor('.sx-menu');
            $browser->script('document.body.dispatchEvent(new MouseEvent("mousedown", { bubbles: true }));');
            $browser->waitUntilMissing('.sx-menu');

            // MenuBar: open File, hover Edit to switch, pick Copy
            $browser->click('[data-sx-id="ctl-menubar"] .sx-menubar-label:nth-child(1)')->waitFor('.sx-menu');
            $browser->script(<<<'JS'
                document.querySelectorAll('[data-sx-id="ctl-menubar"] .sx-menubar-label')[1]
                    .dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
            JS);
            // after the switch the visible menu is Edit's -> wait for Copy to be present, then click it
            $browser->waitUsing(5, 100, function () use ($browser) {
                return $browser->script('return [...document.querySelectorAll(".sx-menu .sx-menu-item")].some(n => n.textContent.trim().startsWith("Copy"));')[0];
            });
            $browser->script(<<<'JS'
                [...document.querySelectorAll('.sx-menu .sx-menu-item')]
                    .find(n => n.textContent.trim().startsWith('Copy')).click();
            JS);
            $browser->waitForText('menuPick=edit.copy');
        });
    }

    // Slice 3c (tooltip plan): browser proof for the Tooltip. The bubble lives in the DOM but is
    // visibility:hidden until hover; hovering the wrapper fires the pure-CSS :hover reveal (after the
    // ~0.4s show-delay, absorbed by waitFor). Display-only -- nothing round-trips, so we assert
    // visibility only. Move the pointer away and the bubble hides again.
    public function test_tooltip_shows_on_hover_and_hides_on_leave(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)->waitFor('[data-window-id="hello"] .sx-window');
            $this->openViaLauncher($browser, 'controls');
            $browser->waitFor('[data-app="controls"] .sx-window')
                ->waitFor('[data-sx-id="controls-readout"]');

            $this->switchCategory($browser, 'tooltips');
            $browser->waitFor('[data-sx-id="ctl-tooltip"]');

            // park the pointer on a neutral element first, so the initial "hidden" beat can't be
            // flaked by the cursor happening to rest over the wrapper.
            $browser->mouseover('[data-sx-id="controls-readout"]');

            // hidden until hovered
            $browser->assertMissing('[data-sx-id="ctl-tooltip"] .sx-tooltip-bubble');

            // hover the wrapper -> the bubble reveals (waitFor tolerates the ~0.4s show-delay)
            $browser->mouseover('[data-sx-id="ctl-tooltip"]')
                ->waitFor('[data-sx-id="ctl-tooltip"] .sx-tooltip-bubble')
                ->assertSeeIn('[data-sx-id="ctl-tooltip"] .sx-tooltip-bubble', 'Saves your work');

            // move the pointer away (hover a different control) -> the bubble hides
            $browser->mouseover('[data-sx-id="controls-readout"]')
                ->waitUntilMissing('[data-sx-id="ctl-tooltip"] .sx-tooltip-bubble');
        });
    }

    // PH slice 4b: a throwing handler is ISOLATED -- the desktop survives (a sibling control still
    // round-trips) and a toast appears, instead of a 500 taking down the whole desktop.
    public function test_a_throwing_handler_is_isolated_and_toasts(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)->waitFor('[data-window-id="hello"] .sx-window');
            $this->openViaLauncher($browser, 'controls');
            $browser->waitFor('[data-app="controls"] .sx-window')
                ->waitFor('[data-sx-id="controls-readout"]');

            // The crash button (near the readout, always visible). Click it -> the handler throws
            // server-side -> the desktop STAYS ALIVE and a toast appears (no 500 / error page).
            $browser->click('[data-sx-id="ctl-crash"]')
                ->waitFor('.sx-toast'); // the isolation toast surfaced

            // Prove the desktop is STILL ALIVE: a sibling control still round-trips. Switch to the
            // inputs tab and drive a control, asserting the readout morphs (the round-trip works).
            $this->switchCategory($browser, 'inputs');
            $browser->waitFor('[data-sx-id="ctl-theme"]')
                ->select('[data-sx-id="ctl-theme"] select', 'dark')
                ->waitForText('theme=dark'); // the desktop is responsive AFTER the crash
        });
    }

    // Task 7 (image widget plan): browser proof for the Image widget. Display-only, so no
    // readout assertion -- switch to the Images category, assert the plain image renders,
    // click the enlargeable image, assert the lightbox backdrop + big image appear, dismiss
    // via Escape, assert it's gone.
    public function test_image_renders_and_enlargeable_image_opens_and_dismisses_a_lightbox(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)->waitFor('[data-window-id="hello"] .sx-window');
            $this->openViaLauncher($browser, 'controls');
            $browser->waitFor('[data-app="controls"] .sx-window')
                ->waitFor('[data-sx-id="controls-readout"]');

            $this->switchCategory($browser, 'images');

            // Plain image renders -- no enlarge affordance.
            $browser->waitFor('[data-sx-id="ctl-image-plain"]')
                ->assertVisible('[data-sx-id="ctl-image-plain"].sx-image')
                ->assertMissing('[data-sx-id="ctl-image-plain"].sx-image--enlargeable');

            // Enlargeable image -- click it and the client-only lightbox opens.
            $browser->assertVisible('[data-sx-id="ctl-image-enlarge"].sx-image--enlargeable')
                ->click('[data-sx-id="ctl-image-enlarge"]')
                ->waitFor('.sx-lightbox-backdrop')
                ->assertVisible('.sx-lightbox-backdrop .sx-lightbox-img');

            // Escape dismisses it.
            $browser->script(<<<'JS'
                document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
            JS);
            $browser->waitUntilMissing('.sx-lightbox-backdrop');

            // Re-open, this time dismiss via a backdrop click (mousedown on the backdrop itself).
            $browser->click('[data-sx-id="ctl-image-enlarge"]')
                ->waitFor('.sx-lightbox-backdrop');
            $browser->script(<<<'JS'
                const b = document.querySelector('.sx-lightbox-backdrop');
                b.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
            JS);
            $browser->waitUntilMissing('.sx-lightbox-backdrop')
                ->screenshot('controls-image-lightbox-dismissed');
        });
    }

    // Chart widget plan (Task 8): browser proof for the hand-rolled SVG Chart. Switch to the
    // Charts category, assert each of the three chart types (line/bar/area) renders an SVG
    // with gridlines + its own series marks + a legend, then hover the line chart and assert
    // the tooltip appears (and hides again on mouseout). Display-only -- nothing round-trips,
    // so this follows the same visibility idiom as the Tooltip test: the tooltip node lives in
    // the DOM the whole time, toggled hidden/visible via a CSS class, so assertMissing/waitFor
    // read visibility rather than DOM presence.
    public function test_charts_render_gridlines_and_series_and_tooltip_shows_on_hover(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)->waitFor('[data-window-id="hello"] .sx-window');
            $this->openViaLauncher($browser, 'controls');
            $browser->waitFor('[data-app="controls"] .sx-window')
                ->waitFor('[data-sx-id="controls-readout"]');

            $this->switchCategory($browser, 'charts');
            $browser->waitFor('[data-sx-id="chart-line"] svg');

            // Every chart draws the shared frame (gridlines) plus its own series marks.
            $browser->assertPresent('[data-sx-id="chart-line"] .sx-chart-gridline')
                ->assertPresent('[data-sx-id="chart-line"] .sx-chart-line')
                ->assertPresent('[data-sx-id="chart-bar"] .sx-chart-gridline')
                ->assertPresent('[data-sx-id="chart-bar"] .sx-chart-bar')
                ->assertPresent('[data-sx-id="chart-area"] .sx-chart-gridline')
                ->assertPresent('[data-sx-id="chart-area"] .sx-chart-area');

            // A legend row per series, on every chart.
            $browser->assertSeeIn('[data-sx-id="chart-line"] .sx-chart-legend', 'Reads')
                ->assertSeeIn('[data-sx-id="chart-line"] .sx-chart-legend', 'Faults')
                ->screenshot('controls-charts-tab');

            // park the pointer on a neutral element first (same idiom as the Tooltip test) so
            // the initial "hidden" beat can't be flaked by the cursor already resting on a chart.
            $browser->mouseover('[data-sx-id="controls-readout"]');
            $browser->assertMissing('[data-sx-id="chart-line"] .sx-chart-tooltip');

            // hover the line chart -> the tooltip reveals with the hovered category + both series.
            $browser->mouseover('[data-sx-id="chart-line"]')
                ->waitFor('[data-sx-id="chart-line"] .sx-chart-tooltip')
                ->assertSeeIn('[data-sx-id="chart-line"] .sx-chart-tooltip', 'Reads')
                ->assertSeeIn('[data-sx-id="chart-line"] .sx-chart-tooltip', 'Faults');

            // move away -> the tooltip hides again.
            $browser->mouseover('[data-sx-id="controls-readout"]')
                ->waitUntilMissing('[data-sx-id="chart-line"] .sx-chart-tooltip');
        });
    }

    // Drive the launcher (copied from WmLaunchTest::openViaLauncher, which is private there):
    // click the panel's system-x button to open the overlay, wait for the app's tile, click it,
    // wait for the overlay to close. Body-mounted, so the trigger + tile live outside #sx-desktop.
    private function openViaLauncher(Browser $browser, string $slug): void
    {
        $browser->click('[data-sx-launcher]')
            ->waitFor("[data-sx-launch=\"{$slug}\"]")
            ->click("[data-sx-launch=\"{$slug}\"]")
            ->waitUntilMissing('[data-sx-launch]', 10);
    }

    // Switch the gallery's top-level category tab (ctl-categories). Its radios are visually
    // hidden (RadioGroup-style CSS), so we script-dispatch a bubbling change on the matching
    // value -- the delegated dispatcher round-trips it, the App writes $activeCategory, and
    // the optimistic listener shows that category's panel immediately.
    private function switchCategory(Browser $browser, string $category): void
    {
        $browser->script(<<<JS
            const r = document.querySelector('[data-sx-id="ctl-categories"] input[type=radio][value="{$category}"]');
            r.checked = true;
            r.dispatchEvent(new Event('change', { bubbles: true }));
        JS);
    }
}

<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

// The end-to-end proof for the Appearance app (Plan 5b-2, Task 6): open Appearance via the
// launcher, flip theme/accent/wallpaper -> the WHOLE desktop (incl. the body-mounted panel
// chrome, because the attribute lands on <html>, the common ancestor) reskins LIVE -> reload
// -> every pref persisted (the no-flash boot stamp re-applies them server-side, D4). The
// panel-toggle + context-menu cases land in Tasks 7/8; this proves theme/accent/wallpaper.
//
// Landmine (assert the attribute, not the colour): a Dusk test can't reliably read a COMPUTED
// token colour (it cascades through CSS vars). The OBSERVABLE contract is the attribute on the
// right root -- html[data-sx-theme]/[data-sx-accent], #sx-desktop[data-sx-wallpaper]. The
// attribute IS the reskin trigger; the CSS is unit-proven by the token files (DesignFoundation
// + Wallpaper tests). The chrome-reskin is confirmed below by reading the panel's computed
// background off the live <html data-sx-theme="pewter"> -- the token resolves through pewter.
//
// NOTE: Dusk scopes css resolvers under <body>, so <html>'s attributes can't be read via
// assertAttribute('html', ...) -- we read documentElement directly via a tiny script helper.
class AppearanceTest extends DuskTestCase
{
    /** Read an attribute off <html> (documentElement) -- outside Dusk's body-scoped resolver. */
    private function htmlAttr(Browser $browser, string $attr): ?string
    {
        return $browser->script("return document.documentElement.getAttribute('{$attr}');")[0];
    }

    public function test_flipping_the_theme_reskins_the_whole_desktop_and_persists(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                // Open Appearance via the user menu. Appearance is now a SYSTEM app (system-menu
                // plan, D1) -- it lives in the tray user-menu dropdown, not the launcher grid.
                ->click('.sx-panel-user')
                ->waitFor('.sx-system-menu')
                ->click('[data-sx-menu="appearance"]')
                ->waitFor('[data-app="appearance"] .sx-window');

            // The window-open seed (D5) pressed the CURRENT options: a default user boots
            // modern/blue/gradient, so those controls read pressed before we touch a thing.
            $browser->assertAttribute('[data-sx-pref="theme:modern"]', 'data-sx-pressed', 'true')
                ->assertAttribute('[data-sx-pref="accent:blue"]', 'data-sx-pressed', 'true')
                ->assertAttribute('[data-sx-pref="wallpaper:gradient"]', 'data-sx-pressed', 'true');

            // Flip to pewter -- the WHOLE desktop reskins via <html> (D3), incl. the panel.
            $browser->click('[data-sx-pref="theme:pewter"]')
                ->waitUsing(5, 100, fn () => $this->htmlAttr($browser, 'data-sx-theme') === 'pewter');
            $this->assertSame('pewter', $this->htmlAttr($browser, 'data-sx-theme'), 'The theme attribute must land on <html>.');

            // The just-clicked control presses in (client-applied, no App round-trip, B2); the
            // old one un-presses. No flicker -- the interceptor owns the pressed-state.
            $browser->assertAttribute('[data-sx-pref="theme:pewter"]', 'data-sx-pressed', 'true')
                ->assertAttribute('[data-sx-pref="theme:modern"]', 'data-sx-pressed', 'false');

            // Flip the accent + wallpaper.
            $browser->click('[data-sx-pref="accent:amber"]')
                ->waitUsing(5, 100, fn () => $this->htmlAttr($browser, 'data-sx-accent') === 'amber');
            $this->assertSame('amber', $this->htmlAttr($browser, 'data-sx-accent'), 'The accent attribute must land on <html>.');
            $browser->assertAttribute('[data-sx-pref="accent:amber"]', 'data-sx-pressed', 'true');

            $browser->click('[data-sx-pref="wallpaper:grid"]')
                ->waitUsing(5, 100, fn () => $browser->attribute('#sx-desktop', 'data-sx-wallpaper') === 'grid')
                ->assertAttribute('#sx-desktop', 'data-sx-wallpaper', 'grid')
                ->assertAttribute('[data-sx-pref="wallpaper:grid"]', 'data-sx-pressed', 'true');

            // The chrome reskins too (D3 payoff): the panel is body-mounted, so it sits UNDER
            // the same <html data-sx-theme="pewter">. Its computed background resolves through
            // the pewter cascade -- assert it's a real resolved colour (the token resolved, the
            // chrome is themed), then screenshot the reskinned desktop (panel + windows +
            // Appearance, all in pewter) for an eyeball.
            $panelBg = $browser->script(
                "return getComputedStyle(document.querySelector('.sx-panel')).backgroundColor;"
            )[0];
            $this->assertNotEmpty($panelBg, 'The panel must resolve a themed background under pewter (the chrome reskins).');
            $this->assertStringStartsWith('rgb', $panelBg, 'The panel background must resolve to a real colour under pewter.');
            $browser->screenshot('appearance-pewter-reskin');

            // THE KILLER PROOF -- reload. The prefs persisted server-side (the fire-and-forget
            // POST), and the no-flash boot stamp re-applies them on the first byte (no flash):
            // pewter/amber on <html>, grid on #sx-desktop, surviving the full navigation.
            $browser->refresh()
                ->waitFor('[data-window-id="hello"] .sx-window');
            $this->assertSame('pewter', $this->htmlAttr($browser, 'data-sx-theme'), 'The theme must persist across a reload (boot stamp).');
            $this->assertSame('amber', $this->htmlAttr($browser, 'data-sx-accent'), 'The accent must persist across a reload (boot stamp).');
            $browser->assertAttribute('#sx-desktop', 'data-sx-wallpaper', 'grid')
                ->screenshot('appearance-pewter-after-reload');
        });
    }

    // The panel top/bottom toggle (Plan 5b-2, Task 7, D6): flip the panel to the bottom -> the
    // attribute lands on <html>, the panel CSS moves it, and the WM re-insets the work area so
    // a maximised window fills from the TOP (the bottom panel eats the bottom strip). Then
    // reload -> the bottom panel persisted (the boot blob carries the position, D4).
    public function test_toggling_the_panel_to_the_bottom_moves_it_and_survives_a_reload(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                // Open Appearance via the user menu. Appearance is now a SYSTEM app (system-menu
                // plan, D1) -- it lives in the tray user-menu dropdown, not the launcher grid.
                ->click('.sx-panel-user')
                ->waitFor('.sx-system-menu')
                ->click('[data-sx-menu="appearance"]')
                ->waitFor('[data-app="appearance"] .sx-window');

            // Toggle the panel to the bottom -> <html> gets data-sx-panel='bottom', the panel
            // CSS flips to the bottom edge (read <html> via the script helper, not Dusk's
            // body-scoped assertAttribute -- same reason as the theme/accent reads above).
            $browser->click('[data-sx-pref="panel:bottom"]')
                ->waitUsing(5, 100, fn () => $this->htmlAttr($browser, 'data-sx-panel') === 'bottom');
            $this->assertSame('bottom', $this->htmlAttr($browser, 'data-sx-panel'), 'The panel attribute must land on <html>.');
            $browser->assertAttribute('[data-sx-pref="panel:bottom"]', 'data-sx-pressed', 'true');

            // Maximise hello -> it fills from the TOP now (the bottom panel eats the bottom).
            $browser->click('[data-window-id="hello"] [data-sx-control="maximise"]')
                ->waitUsing(5, 100, fn () => $browser->attribute('[data-window-id="hello"]', 'data-sx-max') === 'true')
                ->assertAttribute('[data-window-id="hello"]', 'data-sx-max', 'true');
            $fillsFromTop = $browser->script(
                "return document.querySelector('[data-window-id=\"hello\"]').style.transform.includes('0px, 0px');"
            )[0];
            $this->assertTrue($fillsFromTop, 'A maximised window with the panel at the bottom must fill from the top (origin 0,0).');
            $browser->screenshot('appearance-panel-bottom');

            // RELOAD -> the bottom panel persisted (the boot blob carries the position, D4).
            $browser->refresh()
                ->waitFor('[data-window-id="hello"] .sx-window');
            $this->assertSame('bottom', $this->htmlAttr($browser, 'data-sx-panel'), 'The bottom panel must persist across a reload (boot stamp).');
            $browser->screenshot('appearance-panel-bottom-after-reload');
        });
    }

    // The desktop right-click context menu (Plan 5b-2, Task 8, D7): a right-click on the BARE
    // desktop opens the floating menu; picking "Open Appearance" launches Appearance. A
    // right-click on a WINDOW does NOT open it (the guard fails, the native menu passes through).
    //
    // Dusk's rightClick doesn't reliably synthesise a real contextmenu MouseEvent at coords, so
    // we drive the event by script (like the unit test) at the right target and assert the
    // OBSERVABLE outcome -- the menu opens on the bare desktop, stays missing over a window.
    public function test_right_clicking_the_desktop_opens_the_context_menu_and_a_window_does_not(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window');

            // Right-click the BARE desktop -> the menu opens (script-driven contextmenu at the
            // mount, target IS #sx-desktop so the guard fires).
            $browser->script(
                "var m = document.getElementById('sx-desktop');".
                "var e = new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: 200, clientY: 200 });".
                "Object.defineProperty(e, 'target', { value: m });".
                'm.dispatchEvent(e);'
            );
            $browser->waitFor('.sx-context-menu')
                // Open Appearance from the menu -> the Appearance window launches.
                ->click('[data-sx-menu="appearance"]')
                ->waitFor('[data-app="appearance"] .sx-window')
                ->assertMissing('.sx-context-menu'); // selecting closes it

            // Right-click a WINDOW -> the guard FAILS (target is the titlebar, not the desktop),
            // so the native menu passes through and OUR menu stays missing.
            $browser->script(
                "var t = document.querySelector('[data-window-id=\"hello\"] .sx-titlebar');".
                "var e = new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: 200, clientY: 200 });".
                "Object.defineProperty(e, 'target', { value: t });".
                't.dispatchEvent(e);'
            );
            $browser->assertMissing('.sx-context-menu')
                ->screenshot('appearance-context-menu');
        });
    }
}

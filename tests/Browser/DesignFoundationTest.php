<?php

namespace Tests\Browser;

use Facebook\WebDriver\Interactions\WebDriverActions;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class DesignFoundationTest extends DuskTestCase
{
    // Fire a GENUINE pointer press (pointerdown/up/click) inside the notes window's exposed
    // right strip. notes is cascaded 28px down-right of the boot-focused hello and so is
    // partly buried under it; only the right edge pokes out. We move to that strip (well
    // clear of the titlebar controls) and click via WebDriver, which -- unlike clickAtPoint
    // -- fires the real pointerdown the WM's raise listener keys off.
    private function pressInExposedStrip(Browser $browser): void
    {
        $notes = $browser->element('[data-window-id="notes"]');

        // moveToElement offsets are measured from the element CENTRE. notes is 360x220,
        // so +164 reaches the right strip (x ~ centre+164, well past hello's right edge)
        // and +22 drops just below the titlebar controls -- a bare, exposed bit of window.
        (new WebDriverActions($browser->driver))
            ->moveToElement($notes, 164, 22)
            ->click()
            ->perform();
    }

    // Visual proof that the design-system FOUNDATION is live: the desktop loads the
    // vendored tokens + base.css via Vite, the renderers emit the class hooks base.css
    // targets, and the modern default theme paints the chrome. This visits the real
    // logged-in desktop, waits for both styled windows, and captures a screenshot so
    // the styled result (titlebar, framed window, styled button, desktop wallpaper)
    // can be eyeballed -- not bare boxes.

    public function test_the_logged_in_desktop_renders_in_the_modern_theme(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="notes"] .sx-window')
                // The class hooks base.css styles must be present on the live DOM.
                ->assertPresent('.sx-desktop')
                ->assertPresent('[data-window-id="hello"] .sx-window .sx-titlebar')
                ->assertPresent('[data-window-id="hello"] .sx-window .sx-content')
                ->assertPresent('[data-window-id="hello"] .sx-window .sx-button')
                // The active cue is now WM-owned on the SURFACE (Plan 5a, D3), driven by
                // REAL focus (Task 3). The WM boot-focuses the first window, so hello's
                // surface is active and notes' is not -- the surfaces are absolute-positioned
                // (cascade).
                ->assertAttribute('[data-window-id="hello"]', 'data-sx-active', 'true')
                ->assertAttribute('[data-window-id="notes"]', 'data-sx-active', 'false')
                // Prove it's REAL focus, not a static boot stamp: click the notes window and
                // focus follows -- notes goes active, hello goes inactive. notes is cascaded
                // down-right of (and partly under) the boot-focused hello, so a real pointer
                // press lands in its EXPOSED right strip -- a pointerdown ANYWHERE in the
                // window raises it (D6). A real WebDriver click is used (not clickAtPoint,
                // which fires only a JS click and no pointerdown -- the raise listens on
                // pointerdown so a drag can start on press, Task 4).
                ->tap(fn (Browser $b) => $this->pressInExposedStrip($b))
                ->waitUsing(5, 100, fn () => $browser->attribute('[data-window-id="notes"]', 'data-sx-active') === 'true')
                ->assertAttribute('[data-window-id="notes"]', 'data-sx-active', 'true')
                ->assertAttribute('[data-window-id="hello"]', 'data-sx-active', 'false')
                ->screenshot('window-focus-raised')
                // The framework-owned chrome controls (Plan 5a, D5): every window's titlebar
                // carries a close + a maximise control. They are inert this task (focus/drag/
                // maximise/close wire up in Tasks 3-5/9) -- this just proves the styled chrome
                // paints. Close-hover red is a visual, eyeballed in the screenshot.
                ->assertPresent('[data-window-id="hello"] .sx-titlebar .sx-titlebar-text')
                ->assertPresent('[data-window-id="hello"] .sx-window-control-close')
                ->assertPresent('[data-window-id="hello"] .sx-window-control-maximise')
                ->assertPresent('[data-window-id="notes"] .sx-window-control-close')
                ->screenshot('design-foundation')
                ->screenshot('window-chrome');
        });
    }

    // Visual proof for the RICHER widgets (Foundation B): the text field renders as a
    // sunken well, the checkbox as a sunken box + glyph, the list as a sunken well of
    // rows, all inside the styled Stack -- coherent in the modern theme. The list rows
    // only exist once HelloApp's count is > 0, so we click to spawn a few, type into
    // the field, then capture. Asserts the widget hooks widgets.css styles are present
    // on the live DOM, then screenshots the populated desktop.
    public function test_the_richer_widgets_render_styled_on_the_live_desktop(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window')
                ->waitFor('[data-window-id="notes"] .sx-window')
                // Spawn list rows: each click adds one keyed row (capped at 5).
                ->click('[data-window-id="hello"] [data-sx-id="clicker"]')
                ->waitForTextIn('[data-window-id="hello"] [data-sx-id="counter"]', 'Clicked 1 times', 10)
                ->click('[data-window-id="hello"] [data-sx-id="clicker"]')
                ->click('[data-window-id="hello"] [data-sx-id="clicker"]')
                ->waitForTextIn('[data-window-id="hello"] [data-sx-id="counter"]', 'Clicked 3 times', 10)
                // Put some text in the sunken well so the field reads as a real input.
                ->type('[data-window-id="hello"] [data-sx-id="note"] input', 'design system')
                ->type('[data-window-id="notes"] [data-sx-id="message-field"] input', 'buy milk')
                // The widget hooks widgets.css styles must be present on the live DOM.
                ->assertPresent('[data-window-id="hello"] .sx-stack')
                ->assertPresent('[data-window-id="hello"] .sx-textfield input')
                ->assertPresent('[data-window-id="hello"] .sx-checkbox .sx-checkbox-label')
                ->assertPresent('[data-window-id="hello"] .sx-list')
                ->assertPresent('[data-window-id="hello"] .sx-listitem')
                ->screenshot('design-foundation-widgets');
        });
    }
}

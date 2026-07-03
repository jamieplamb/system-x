<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ToolkitMorphTest extends DuskTestCase
{
    public function test_a_focused_field_edit_survives_a_server_re_render(): void
    {
        // Proves FOCUSED-INPUT-WINS: an edit in a STILL-FOCUSED field survives a
        // server re-render. We trigger the re-render with a FOCUS-NEUTRAL push
        // (`system-x:push`, the same mechanism ReverbRoundTripTest uses) rather than
        // ->click('[data-sx-id="clicker"]'), because a click would STEAL focus from
        // the field before the frame arrives -- and once unfocused, the stateless
        // server's empty value would legitimately overwrite the local edit. This is
        // NOT a test hack: with a stateless server that never persists field values
        // (until the durable state store lands in a later plan), an UNFOCUSED field
        // genuinely cannot survive an unrelated re-render -- there is no mechanism
        // for it. So we assert the only thing that IS provable today: the edit holds
        // while the input keeps focus.
        $this->browse(function (Browser $browser): void {
            // Log in first -- the desktop requires auth (D3).
            $this->loginAsDemoUser($browser)
                ->waitFor('.sx-window')
                ->waitFor('[data-sx-id="note"] input')
                // Type a live value into the field (LOCAL -- no POST per keystroke).
                ->type('[data-sx-id="note"] input', 'half typed')
                ->click('[data-sx-id="note"] input');       // keep focus ON the field

            // Read the desktop id and push a re-render WITHOUT touching the browser,
            // so focus stays on the field as the frame arrives.
            // HARD RULE (S5): read the push id off the live page -- data-desktop-id is
            // now the demo USER's id. NEVER hardcode it: a wrong id pushes to a channel
            // nobody is subscribed to and the test hangs on the waitForChannel below.
            $desktopId = $browser->attribute('#sx-desktop', 'data-desktop-id');
            // boot() subscribes to the private channel AFTER the initial fetch, and
            // Reverb does not replay frames missed before a subscription lands -- so
            // block until the channel is subscribed before pushing, or the pushed
            // frame can race ahead of the listener and be dropped.
            $this->waitForChannel($browser, $desktopId);
            $this->artisan('system-x:push', ['desktopId' => $desktopId, 'count' => 1]);

            $browser->waitForTextIn('[data-sx-id="counter"]', 'Clicked 1 times', 10)
                // The morph must NOT have clobbered the STILL-FOCUSED field's value.
                ->assertInputValue('[data-sx-id="note"] input', 'half typed');
        });
    }

    public function test_a_list_grows_by_morph_not_full_rebuild(): void
    {
        $this->browse(function (Browser $browser): void {
            // Log in first -- the desktop requires auth (D3).
            $this->loginAsDemoUser($browser)->waitFor('.sx-window');

            // Tag the existing list container; if the morph rebuilds it, the tag vanishes.
            $browser->script("document.querySelector('[data-sx-id=\"items\"]').dataset.probe = 'kept';");

            $browser->click('[data-sx-id="clicker"]')
                ->waitForTextIn('[data-sx-id="counter"]', 'Clicked 1 times', 10)
                ->waitFor('[data-sx-id="item-1"]');

            // The container is the SAME element (probe survived) -> morph, not rebuild.
            $probe = $browser->attribute('[data-sx-id="items"]', 'data-probe');
            $this->assertSame('kept', $probe);
        });
    }

    public function test_a_focused_checkbox_toggle_survives_a_server_re_render(): void
    {
        // Same focused-input-wins guarantee as the field test, for the checkbox. We
        // re-render with a FOCUS-NEUTRAL `system-x:push` rather than clicking the
        // clicker, because the click would steal focus from the box -- and once
        // unfocused, the stateless server's `checked: false` would legitimately snap
        // it back. An UNFOCUSED toggle cannot survive an unrelated re-render until
        // the durable state store lands (later plan); a FOCUSED one can, and that is
        // what we assert.
        $this->browse(function (Browser $browser): void {
            // Log in first -- the desktop requires auth (D3).
            $this->loginAsDemoUser($browser)
                ->waitFor('.sx-window')
                ->waitFor('[data-sx-id="notify"] input')
                // Toggle the box on (the live checked state is local). check() clicks
                // it, which both checks AND focuses it -- so it stays document.activeElement
                // for the push below. We do NOT add a second ->click() here: unlike a text
                // field (where a refocusing click is harmless), a second click on a checkbox
                // toggles it straight back OFF.
                ->check('[data-sx-id="notify"] input')
                ->assertChecked('[data-sx-id="notify"] input');

            // Read the desktop id and push a re-render WITHOUT touching the browser,
            // so focus stays on the checkbox as the frame arrives.
            // HARD RULE (S5): read the push id off the live page -- data-desktop-id is
            // now the demo USER's id. NEVER hardcode it: a wrong id pushes to a channel
            // nobody is subscribed to and the test hangs on the waitForChannel below.
            $desktopId = $browser->attribute('#sx-desktop', 'data-desktop-id');
            // Same subscription barrier as the field test: wait until the private
            // channel is live before pushing, so the frame can't outrun the listener.
            $this->waitForChannel($browser, $desktopId);
            $this->artisan('system-x:push', ['desktopId' => $desktopId, 'count' => 1]);

            $browser->waitForTextIn('[data-sx-id="counter"]', 'Clicked 1 times', 10)
                // The morph must NOT have snapped the STILL-FOCUSED box back to server state.
                ->assertChecked('[data-sx-id="notify"] input');
        });
    }

    public function test_raw_content_survives_an_unrelated_re_render(): void
    {
        $this->browse(function (Browser $browser): void {
            // Log in first -- the desktop requires auth (D3).
            $this->loginAsDemoUser($browser)->waitFor('.sx-window')->waitFor('[data-sx-id="raw-note"]');

            // Tag the raw container; if the morph rewrites its innerHTML, the tag vanishes.
            $browser->script("document.querySelector('[data-sx-id=\"raw-note\"]').querySelector('.sx-raw-note').dataset.probe = 'kept';");

            $browser->click('[data-sx-id="clicker"]')
                ->waitForTextIn('[data-sx-id="counter"]', 'Clicked 1 times', 10);

            // raw is opaque + unchanged, so its inner node (and the probe) survive.
            $probe = $browser->attribute('[data-sx-id="raw-note"] .sx-raw-note', 'data-probe');
            $this->assertSame('kept', $probe);
        });
    }

    public function test_a_checkbox_toggle_echoes_its_boolean_both_ways_over_the_wire(): void
    {
        // Carried-forward Task 7 browser check: the change event must round-trip the
        // checkbox's BOOLEAN `checked` -- true when ticked, false when unticked. The
        // stateless server ignores the value, so we observe it where it actually rides
        // the wire: by capturing the outgoing POST body to /system-x/event in a real
        // browser (the dispatcher's liveValue() path, proven in jsdom, now in Chrome).
        $this->browse(function (Browser $browser): void {
            // Log in first -- the desktop requires auth (D3).
            $this->loginAsDemoUser($browser)
                ->waitFor('.sx-window')
                ->waitFor('[data-sx-id="notify"] input');

            // Record every event POST body so we can read the echoed value out of it.
            $browser->script(<<<'JS'
                window.__sxPosts = [];
                const orig = window.fetch;
                window.fetch = function (url, opts) {
                    if (typeof url === 'string' && url.includes('/system-x/event') && opts && opts.body) {
                        window.__sxPosts.push(JSON.parse(opts.body));
                    }
                    return orig.apply(this, arguments);
                };
            JS);

            // Tick it (echo true), then untick it (echo false). A native change fires
            // on each toggle and the delegated dispatcher POSTs the boolean.
            $browser->check('[data-sx-id="notify"] input')
                ->uncheck('[data-sx-id="notify"] input');

            // Pull the two notify-change posts back out and assert both directions.
            $values = $browser->script(
                "return window.__sxPosts.filter(p => p.widget === 'notify' && p.event === 'change').map(p => p.value);"
            )[0];

            $this->assertSame([true, false], $values);
        });
    }

    public function test_enter_submits_over_the_wire_without_a_form_reload(): void
    {
        // Carried-forward Task 7 browser check. Two things to prove in a real browser:
        // (1) the field is NOT inside a <form>, so a native Enter cannot trigger a
        //     submit-navigation that reloads the page on top of the WS round-trip; and
        // (2) Enter in the field round-trips a 'submit' carrying the live value, and
        //     the page does NOT navigate (the SPA shell stays put).
        $this->browse(function (Browser $browser): void {
            // Log in first -- the desktop requires auth (D3).
            $this->loginAsDemoUser($browser)
                ->waitFor('.sx-window')
                ->waitFor('[data-sx-id="note"] input');

            // (1) No <form> ancestor: a native Enter has nothing to submit-navigate.
            $insideForm = $browser->script(
                "return document.querySelector('[data-sx-id=\"note\"] input').closest('form') !== null;"
            )[0];
            $this->assertFalse($insideForm, 'The TextField must not sit inside a <form> (native Enter would reload).');

            // Capture event POSTs + a page-load sentinel. If a native submit navigated,
            // the sentinel would be wiped by the reload.
            $browser->script(<<<'JS'
                window.__sxReloaded = false;
                window.addEventListener('beforeunload', () => { window.__sxReloaded = true; });
                window.__sxPosts = [];
                const orig = window.fetch;
                window.fetch = function (url, opts) {
                    if (typeof url === 'string' && url.includes('/system-x/event') && opts && opts.body) {
                        window.__sxPosts.push(JSON.parse(opts.body));
                    }
                    return orig.apply(this, arguments);
                };
            JS);

            // (2) Type then hit Enter -- this should POST a 'submit' carrying the value.
            $browser->type('[data-sx-id="note"] input', 'ship it')
                ->keys('[data-sx-id="note"] input', '{enter}');

            $browser->waitUsing(5, 100, function () use ($browser) {
                return $browser->script(
                    "return window.__sxPosts.some(p => p.widget === 'note' && p.event === 'submit');"
                )[0];
            });

            $submit = $browser->script(
                "return window.__sxPosts.find(p => p.widget === 'note' && p.event === 'submit');"
            )[0];
            $this->assertSame('ship it', $submit['value']);

            // The page must NOT have reloaded (no native form submit navigation).
            $reloaded = $browser->script('return window.__sxReloaded;')[0];
            $this->assertFalse($reloaded, 'Enter must not have reloaded the page.');
        });
    }
}

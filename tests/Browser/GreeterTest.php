<?php

namespace Tests\Browser;

use App\Support\RememberedUser;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

// The end-to-end proof of the greeter (Plan 5c, Task 7): the remember-last-user
// round-trip in a real browser. Log in via the greeter form -> the desktop boot writes
// the cosmetic cookie -> visiting /login AGAIN reskins the greeter to the user's look,
// greets them by name, and pre-fills their email -> "Not you?" wipes it back to the blank
// brand default. The host GreeterTest pins the markup; THIS pins the live cookie round-trip
// (encrypt on the boot response, decrypt + validate on the next /login) that PHPUnit's
// in-memory ->withCookie() can only approximate.
class GreeterTest extends DuskTestCase
{
    /** Read an attribute off <html> (documentElement) -- outside Dusk's body-scoped resolver. */
    private function htmlAttr(Browser $browser, string $attr): ?string
    {
        return $browser->script("return document.documentElement.getAttribute('{$attr}');")[0];
    }

    public function test_the_greeter_remembers_the_user_then_not_you_resets_it(): void
    {
        $this->browse(function (Browser $browser): void {
            // S3 isolation: the reused Dusk browser session may carry an sx_last_user cookie
            // from an earlier method (DuskTestCase::setUp truncates the per-user tables but
            // can't reach the cookie -- the browser doesn't exist at setUp). Land on the app
            // origin, drop the cookie, and PROVE a fresh guest first, so the round-trip below
            // is a clean start and not a leaked greeting.
            $browser->visit('/login');
            $browser->deleteCookie(RememberedUser::NAME);
            $browser->visit('/login')
                ->waitFor('input[name="email"]')
                ->assertDontSee('Welcome back')
                ->assertDontSee('Not you?');
            $this->assertSame('dark', $this->htmlAttr($browser, 'data-sx-theme'), 'A fresh guest gets the dark brand default.');

            // Log in via the REAL greeter form -> land on the desktop. The boot queues the
            // remember-cookie (modern/blue, the demo user's default look, + name + email).
            $this->loginAsDemoUser($browser)
                ->waitFor('[data-window-id="hello"] .sx-window');

            // Flip the theme to pewter via the Appearance app, then re-boot `/` so the next
            // cookie write carries pewter -- a look UNMISTAKABLY different from the greeter's
            // dark default, so the reskin on the second /login visit is observable. Appearance
            // is now a SYSTEM app (system-menu plan, D1) -- it lives in the user menu, not the
            // launcher grid -- so open it from the dropdown, not a launcher tile.
            $browser->click('.sx-panel-user')
                ->waitFor('.sx-system-menu')
                ->click('[data-sx-menu="appearance"]')
                ->waitFor('[data-app="appearance"] .sx-window')
                ->click('[data-sx-pref="theme:pewter"]')
                ->waitUsing(5, 100, fn () => $this->htmlAttr($browser, 'data-sx-theme') === 'pewter')
                // Re-boot so the cookie is rewritten from the now-pewter prefs.
                ->visit('/')
                ->waitFor('[data-window-id="hello"] .sx-window');

            // Log out -- the SESSION ends, but the cosmetic sx_last_user cookie is NOT the
            // session (logout invalidates the session + CSRF token, never the remember-cookie),
            // so it survives. This is what makes the greeter "remember" you: the auth is gone,
            // the look is kept. /login is guest-gated, so we must be a guest to see the greeter.
            $this->logoutViaMenu($browser);

            // THE PROOF: now a guest, the greeter is reskinned to pewter, greets the demo user
            // by name, and pre-fills their email -- the cookie round-trip end-to-end.
            $browser->waitFor('.sx-greeter-card')
                ->assertSee('Welcome back')
                ->assertSee('Demo User')
                ->assertSee('Not you?')
                ->assertValue('input[name="email"]', 'demo@system-x.test');
            $this->assertSame('pewter', $this->htmlAttr($browser, 'data-sx-theme'), 'The remembered greeter reskins to the user theme.');

            // The payoff shot: the remembered-user greeter, reskinned + greeting by name.
            $browser->screenshot('greeter-remembered-user');

            // "Not you?" -> the blank brand state: no name, empty email, the dark default.
            $browser->click('.sx-greeter-forget')
                ->waitFor('input[name="email"]')
                ->assertDontSee('Welcome back')
                ->assertDontSee('Demo User')
                ->assertDontSee('Not you?')
                ->assertValue('input[name="email"]', '');
            $this->assertSame('dark', $this->htmlAttr($browser, 'data-sx-theme'), '"Not you?" resets to the dark brand default.');
        });
    }
}

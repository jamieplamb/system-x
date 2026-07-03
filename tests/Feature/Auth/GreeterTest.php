<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// The greeter VIEW (Plan 5c, Task 3) -- the branded lock-screen that replaces the plain
// login form. A pure restyle: the auth mechanism (authenticate/logout/routes/throttle/gate)
// is untouched, so these tests pin only what the GET /login VIEW renders -- the no-flash
// brand stamp, the css bundle, the server-seeded clock/date, the remembered-user reskin +
// greeting + email prefill, and the D3 XSS escaping. The form contract (name=email/password,
// action=/login, "Sign in", @error('email')) MUST survive the restyle verbatim.
class GreeterTest extends TestCase
{
    use RefreshDatabase;

    // Build the remember-cookie JSON a returning user would carry. The tests hand this to
    // ->withCookie(), which encrypts it the way a real sx_last_user cookie is encrypted, so
    // EncryptCookies decrypts it back to this JSON before the controller reads it -- mirroring
    // the production round-trip exactly (RememberedUser::fromRequest sees the plaintext).
    private function rememberCookie(array $overrides = []): string
    {
        return json_encode(array_merge([
            'theme' => 'pewter',
            'accent' => 'violet',
            'name' => 'Ada Lovelace',
            'email' => 'ada@system-x.test',
        ], $overrides));
    }

    public function test_a_fresh_guest_sees_the_blank_brand_default(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            // The no-flash brand stamp: dark theme, blue accent on <html> (literals, no cookie).
            ->assertSee('data-sx-theme="dark"', false)
            ->assertSee('data-sx-accent="blue"', false)
            // No remembered user -> no name greeting, no "Not you?" link.
            ->assertDontSee('Welcome back')
            ->assertDontSee('Not you?');

        // An empty email field (a fresh guest types their own).
        $response->assertSee('name="email"', false);
        $response->assertSee('value=""', false);
    }

    public function test_a_returning_user_is_reskinned_greeted_and_prefilled(): void
    {
        $response = $this->withCookie('sx_last_user', $this->rememberCookie())
            ->get('/login');

        $response->assertOk()
            // Reskinned to the remembered look (no-flash) -- not the dark/blue default.
            ->assertSee('data-sx-theme="pewter"', false)
            ->assertSee('data-sx-accent="violet"', false)
            // Greeted by name.
            ->assertSee('Welcome back')
            ->assertSee('Ada Lovelace')
            // The email field is pre-filled with the remembered address.
            ->assertSee('value="ada@system-x.test"', false)
            // The escape hatch back to the blank state.
            ->assertSee('Not you?')
            ->assertSee('/login?forget=1', false);
    }

    public function test_the_auth_form_contract_survives_the_restyle(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            // The form posts to /login (the locked action).
            ->assertSee('action="/login"', false)
            ->assertSee('method="POST"', false)
            // The fields the controller + the Dusk helper depend on.
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            // The CSRF token field (rendered by @csrf).
            ->assertSee('name="_token"', false)
            // The submit label the Dusk helper presses.
            ->assertSee('Sign in');
    }

    public function test_the_email_validation_error_is_surfaced(): void
    {
        // A failed login flashes an `email` error; the greeter must render it (the @error
        // slot). Drive the REAL flow: a bad POST from /login redirects back with the error
        // bag flashed, then following that redirect renders the greeter with the message.
        User::factory()->create([
            'email' => 'demo@system-x.test',
            'password' => Hash::make('password'),
        ]);

        $response = $this->from('/login')
            ->followingRedirects()
            ->post('/login', [
                'email' => 'demo@system-x.test',
                'password' => 'wrong',
            ]);

        $response->assertOk()
            ->assertSee('These credentials do not match our records.');
    }

    public function test_the_greeter_loads_the_design_system_css_bundle(): void
    {
        // B1: the plain login page loaded ZERO css. Without the system-x.css bundle every
        // var(--sx-*) resolves to nothing and the greeter is unstyled garbage. The greeter now
        // emits the package-served bundle via @systemxStyles -> /system-x/assets/system-x.<hash>.css,
        // so assert that hashed asset stem is referenced -- proves the design-system bundle loads.
        $this->get('/login')
            ->assertOk()
            ->assertSee('/system-x/assets/system-x.', false);
    }

    public function test_the_clock_and_date_are_server_seeded(): void
    {
        // D5: the initial time + date are rendered server-side (no blank-then-pop). The
        // greeter.js tick takes over live, but the first paint already shows now().
        $response = $this->get('/login');

        $response->assertOk()
            ->assertSee('data-sx-clock', false)
            ->assertSee('data-sx-date', false)
            // The seed is now()'s HH:MM + a human date -- assert the actual values are present.
            ->assertSee(now()->format('H:i'), false)
            ->assertSee(now()->translatedFormat('l j F'), false);
    }

    public function test_forget_clears_the_remember_cookie_and_renders_the_blank_brand_state(): void
    {
        // "Not you?" (D4): ?forget=1 is the escape hatch. Even with a remember-cookie that
        // WOULD reskin + greet + prefill, hitting ?forget=1 must (a) queue a forget for the
        // cookie so the next visit is a clean guest, and (b) render the blank brand default --
        // never the remembered look. The machine is never stuck greeting one person.
        $response = $this->withCookie('sx_last_user', $this->rememberCookie())
            ->get('/login?forget=1');

        // The cookie that would have reskinned the page is queued for deletion (past expiry).
        $response->assertCookieExpired('sx_last_user');

        $response->assertOk()
            // The blank brand default -- the remembered pewter/violet look is IGNORED.
            ->assertSee('data-sx-theme="dark"', false)
            ->assertSee('data-sx-accent="blue"', false)
            ->assertDontSee('data-sx-theme="pewter"', false)
            // No name greeting, no remembered email, no "Not you?" link.
            ->assertDontSee('Welcome back')
            ->assertDontSee('Ada Lovelace')
            ->assertDontSee('Not you?')
            ->assertSee('value=""', false);
    }

    public function test_forget_with_no_cookie_is_an_idempotent_blank_brand_state(): void
    {
        // ?forget=1 must be safe to hit with no cookie present (a bookmarked/shared link, or a
        // second click) -- it still renders the blank brand state, no error, no greeting.
        $response = $this->get('/login?forget=1');

        $response->assertOk()
            ->assertSee('data-sx-theme="dark"', false)
            ->assertSee('data-sx-accent="blue"', false)
            ->assertDontSee('Welcome back')
            ->assertDontSee('Not you?')
            ->assertSee('value=""', false);
    }

    public function test_a_script_tag_in_the_name_is_escaped(): void
    {
        // D3 XSS guard: the name is display-only and Blade-escaped via {{ }} -- a <script>
        // in the remembered name (and its derived avatar initials) must NEVER render raw.
        $response = $this->withCookie('sx_last_user', $this->rememberCookie([
            'name' => '<script>alert(1)</script>',
        ]))->get('/login');

        $response->assertOk();

        // The raw tag must not appear anywhere in the greeting or the avatar initials.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $response->getContent());
        // But the escaped form proves the name was still rendered (just safely).
        $response->assertSee('&lt;script&gt;', false);
    }
}

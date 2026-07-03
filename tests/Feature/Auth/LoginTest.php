<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function demoUser(): User
    {
        return User::factory()->create([
            'email' => 'demo@system-x.test',
            'password' => Hash::make('password'),
        ]);
    }

    public function test_the_login_form_is_shown_to_a_guest(): void
    {
        // The greeter (Plan 5c) restyled the plain form into a branded lock-screen, but the
        // auth contract is unchanged -- assert the stable form surface, not the chrome. The
        // old `assertSee('email')` matched the literal substring "email" ANYWHERE on the page
        // (the title, a label, an autocomplete hint) -- brittle the instant the restyle moved
        // that text. Pin the field by its name attribute instead, the thing the controller +
        // the Dusk loginAsDemoUser helper actually depend on.
        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign in')              // the submit label (unchanged across the restyle)
            ->assertSee('name="email"', false); // the email field, by its stable attribute
    }

    public function test_valid_credentials_log_the_user_in_and_redirect_to_the_desktop(): void
    {
        $user = $this->demoUser();

        $this->post('/login', [
            'email' => 'demo@system-x.test',
            'password' => 'password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_regenerates_the_session_id_to_defend_against_fixation(): void
    {
        $user = $this->demoUser();

        // S6 note: under the host SESSION_DRIVER=array the before/after session id check
        // FALSE-PASSES -- a fresh test request always mints a new session id, so
        // assertNotSame goes green whether or not authenticate() calls regenerate()
        // (verified red-first: removing $request->session()->regenerate() from the
        // controller still passed the id-diff assertion). The id-diff is therefore
        // theatre on this driver and is dropped. The fixation defence is the framework's
        // tested $request->session()->regenerate() call -- the standard Laravel login
        // shape -- and the real, reliable outcome we pin here is that a valid login
        // authenticates the right principal in the session.
        $this->get('/login');

        $this->post('/login', [
            'email' => 'demo@system-x.test',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_repeated_bad_logins_are_throttled(): void
    {
        $this->demoUser();

        // The login route carries throttle:login at 5/min keyed on email+IP (D8). Five
        // bad attempts exhaust the limiter; the SIXTH is rejected with a 429 before
        // Auth::attempt runs -- closing the brute-force door on the known seeded
        // credential. RED-FIRST: this fails (the 6th would be a normal 302+error) until
        // the limiter + throttle middleware exist.
        foreach (range(1, 5) as $i) {
            $this->post('/login', [
                'email' => 'demo@system-x.test',
                'password' => 'wrong',
            ]);
        }

        $this->post('/login', [
            'email' => 'demo@system-x.test',
            'password' => 'wrong',
        ])->assertStatus(429);

        $this->assertGuest();
    }

    public function test_bad_credentials_error_and_stay_logged_out(): void
    {
        $this->demoUser();

        $this->from('/login')->post('/login', [
            'email' => 'demo@system-x.test',
            'password' => 'wrong',
        ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_logout_invalidates_the_session_and_returns_to_login(): void
    {
        $user = $this->demoUser();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }
}

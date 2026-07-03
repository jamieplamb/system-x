<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\RememberedUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RememberLastUserBootTest extends TestCase
{
    use RefreshDatabase;

    public function test_booting_the_desktop_queues_the_remember_cookie(): void
    {
        // The boot writes the cosmetic remember-cookie (D2) so the greeter can later
        // reskin + greet the returning user. assertCookie decrypts the response cookie,
        // so we can assert the exact JSON payload it carries.
        $user = User::factory()->create([
            'name' => 'Demo User',
            'email' => 'demo@system-x.test',
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertCookie(RememberedUser::NAME, json_encode([
            'theme' => 'modern',
            'accent' => 'blue',
            'name' => 'Demo User',
            'email' => 'demo@system-x.test',
        ]));
    }

    public function test_a_fresh_user_on_default_prefs_still_gets_the_cookie(): void
    {
        // The cookie ALWAYS refreshes on boot -- even a brand-new user with no stored
        // prefs (PreferencesService defaults merged in) leaves with sx_last_user set.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertCookie(RememberedUser::NAME);
    }
}

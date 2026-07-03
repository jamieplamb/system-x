<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DesktopPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_the_desktop_id_and_csrf_meta(): void
    {
        // The shell is now auth-gated and surfaces the authenticated user id as the
        // channel id (D6) -- no more anonymous sx_desktop_id uuid.
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('data-desktop-id="'.$user->id.'"', false);
        $response->assertSee('<meta name="csrf-token"', false);
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        // Nothing is minted any more: a logged-out visitor never reaches the shell,
        // they bounce to /login (the auth gate).
        $this->get('/')->assertRedirect('/login');
    }
}

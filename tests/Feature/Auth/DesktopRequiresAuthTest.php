<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\State\StateKey;
use SystemX\Core\State\WindowState;
use SystemX\Core\Wm\OpenWindowService;
use Tests\TestCase;

class DesktopRequiresAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_from_the_desktop_shell_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_a_guest_cannot_reach_the_desktop_json_endpoint(): void
    {
        // The JSON endpoint 401s a guest (auth middleware: 401 when expectsJson).
        $this->getJson('/system-x/desktop')->assertUnauthorized();
    }

    public function test_a_guest_cannot_reach_the_event_endpoint_or_touch_state(): void
    {
        $this->postJson('/system-x/event', [
            'widget' => 'clicker',
            'event' => 'click',
            'window' => 'hello',
        ])->assertUnauthorized();

        // The gate rejects BEFORE the controller, so no state row is ever written.
        $this->assertSame(0, WindowState::query()->count());
    }

    public function test_a_logged_in_user_passes_the_gate(): void
    {
        $user = User::factory()->create();
        // The resync GET resolves hello's app from the open-set (B4), so seed the pair.
        app(OpenWindowService::class)->seedDefaults(
            new StateKey('user', (string) $user->id, ''),
        );

        $this->actingAs($user)
            ->getJson('/system-x/desktop?window=hello')
            ->assertOk();
    }
}

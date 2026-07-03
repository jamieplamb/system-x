<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\State\StateKey;
use SystemX\Core\State\WindowState;
use SystemX\Core\Wm\OpenWindowService;
use Tests\TestCase;

class PerUserStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_is_keyed_per_user_and_never_crosses_users(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        // Both users need the static pair in their open-set: the tightened event() guard
        // requires hello to be open for Alice, and Bob's resync GET resolves hello's app
        // from his open-set (Task 6, D7/B4).
        $service = app(OpenWindowService::class);
        $service->seedDefaults(new StateKey('user', (string) $alice->id, ''));
        $service->seedDefaults(new StateKey('user', (string) $bob->id, ''));

        // Alice clicks her hello window to 1.
        $this->actingAs($alice)
            ->postJson('/system-x/event', ['widget' => 'clicker', 'event' => 'click', 'window' => 'hello'])
            ->assertNoContent();

        // Bob, logging in, sees a FRESH count for the same window -- NOT Alice's 1.
        $this->actingAs($bob)
            ->getJson('/system-x/desktop?window=hello')
            ->assertOk()
            ->assertJsonPath('children.0.props.text', 'Clicked 0 times');

        // Two separate rows, keyed on the two user ids.
        $this->assertSame(1, WindowState::query()
            ->where('principal_type', 'user')->where('principal_id', (string) $alice->id)->count());
        $this->assertSame(0, WindowState::query()
            ->where('principal_type', 'user')->where('principal_id', (string) $bob->id)->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same reverb-broadcaster trick as before: BROADCAST_CONNECTION=null never runs
        // the channel callback, so point at reverb (Pusher protocol) to enforce the
        // id-match + sign locally. Re-require the channels file against it.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        require __DIR__.'/../../packages/system-x/core/routes/channels.php';
    }

    public function test_a_user_can_authorize_their_own_desktop_channel(): void
    {
        $user = User::factory()->create();

        // The channel is user.{id} now (D6) -- the principal IS the user, so the
        // channel reads as such. The id is the authenticated user id.
        $this->actingAs($user)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => "private-user.{$user->id}",
            ])
            ->assertOk();
    }

    public function test_a_user_cannot_authorize_another_users_desktop_channel(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => "private-user.{$other->id}",
            ])
            ->assertForbidden();
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-user.1',
        ])->assertForbidden();
    }
}

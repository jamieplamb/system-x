<?php

namespace Tests\Feature\Preferences;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Preferences\PreferencesService;
use SystemX\Core\State\StateKey;
use Tests\TestCase;

class PreferencesEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_pref_persists_and_returns_204(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/system-x/prefs', ['key' => 'theme', 'value' => 'pewter'])
            ->assertNoContent();

        $prefs = app(PreferencesService::class)->forPrincipal(new StateKey('user', (string) $user->id, ''));
        $this->assertSame('pewter', $prefs['theme']);
    }

    public function test_the_panel_wire_key_aliases_to_the_panel_position_store_key(): void
    {
        // The client posts the terse 'panel' key (the data-sx-panel hook); the controller
        // aliases it to the 'panel_position' store slot the boot stamp reads (D6) -- without
        // this the panel pref never persists and a reload reverts to top.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/system-x/prefs', ['key' => 'panel', 'value' => 'bottom'])
            ->assertNoContent();

        $prefs = app(PreferencesService::class)->forPrincipal(new StateKey('user', (string) $user->id, ''));
        $this->assertSame('bottom', $prefs['panel_position']);
    }

    public function test_an_unknown_key_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->postJson('/system-x/prefs', ['key' => 'nonsense', 'value' => 'x'])
            ->assertStatus(422);
    }

    public function test_a_disallowed_value_for_a_known_key_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->postJson('/system-x/prefs', ['key' => 'theme', 'value' => 'neon'])
            ->assertStatus(422);
    }

    public function test_a_script_value_is_rejected_and_not_persisted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/system-x/prefs', ['key' => 'theme', 'value' => '<script>alert(1)</script>'])
            ->assertStatus(422);

        // The forged value never reached the bag -- the user is still on the default.
        $prefs = app(PreferencesService::class)->forPrincipal(new StateKey('user', (string) $user->id, ''));
        $this->assertSame('modern', $prefs['theme']);
    }

    public function test_a_guest_is_unauthorised(): void
    {
        $this->postJson('/system-x/prefs', ['key' => 'theme', 'value' => 'pewter'])
            ->assertStatus(401);
    }
}

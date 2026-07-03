<?php

namespace Tests\Feature\Apps;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Launcher\LauncherLayoutService;
use SystemX\Core\State\StateKey;
use Tests\TestCase;

// Task 3 (Plan 4a, Piece 5): POST /system-x/launcher/layout persists the whole-document layout.
// The client owns the arrangement; the server VALIDATES-AND-REJECTS (422) rather than silently
// reshaping, so client and server state can never drift apart.
class LauncherLayoutEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function principal(User $user): StateKey
    {
        return new StateKey('user', (string) $user->id, '');
    }

    public function test_a_valid_layout_persists_and_returns_204(): void
    {
        $user = User::factory()->create();
        $layout = [
            ['type' => 'folder', 'id' => 'f_ab12', 'name' => 'Tools', 'apps' => ['controls', 'notes']],
            ['type' => 'app', 'slug' => 'hello'],
        ];

        $this->actingAs($user)
            ->postJson('/system-x/launcher/layout', ['layout' => $layout])
            ->assertNoContent();

        $stored = app(LauncherLayoutService::class)->layoutFor($this->principal($user));
        $this->assertSame($layout, $stored);
    }

    public function test_a_forged_slug_is_rejected_422(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/system-x/launcher/layout', ['layout' => [['type' => 'app', 'slug' => 'not-a-real-app']]])
            ->assertStatus(422);
    }

    public function test_a_slug_appearing_twice_is_rejected_422(): void
    {
        $layout = [
            ['type' => 'app', 'slug' => 'hello'],
            ['type' => 'folder', 'id' => 'f1', 'name' => 'T', 'apps' => ['hello']],
        ];
        $this->actingAs(User::factory()->create())
            ->postJson('/system-x/launcher/layout', ['layout' => $layout])
            ->assertStatus(422);
    }

    public function test_an_empty_folder_is_accepted(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/system-x/launcher/layout', ['layout' => [['type' => 'folder', 'id' => 'f1', 'name' => 'T', 'apps' => []]]])
            ->assertNoContent();
    }

    public function test_a_duplicate_folder_id_is_rejected_422(): void
    {
        $layout = [
            ['type' => 'folder', 'id' => 'f1', 'name' => 'A', 'apps' => ['hello']],
            ['type' => 'folder', 'id' => 'f1', 'name' => 'B', 'apps' => ['notes']],
        ];
        $this->actingAs(User::factory()->create())
            ->postJson('/system-x/launcher/layout', ['layout' => $layout])
            ->assertStatus(422);
    }

    public function test_an_over_long_folder_name_is_rejected_422(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/system-x/launcher/layout', ['layout' => [['type' => 'folder', 'id' => 'f1', 'name' => str_repeat('x', 41), 'apps' => ['hello']]]])
            ->assertStatus(422);
    }

    public function test_it_requires_auth(): void
    {
        $this->postJson('/system-x/launcher/layout', ['layout' => []])->assertUnauthorized();
    }

    public function test_a_system_app_slug_in_the_layout_is_rejected_422(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/system-x/launcher/layout', ['layout' => [['type' => 'app', 'slug' => 'appearance']]])
            ->assertStatus(422);
    }
}

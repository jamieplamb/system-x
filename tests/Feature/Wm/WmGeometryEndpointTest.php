<?php

namespace Tests\Feature\Wm;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\State\StateKey;
use SystemX\Core\Wm\OpenWindowService;
use Tests\TestCase;

// Task 2 (Plan 5e, D3): POST /system-x/wm/geometry {window, x, y, w, h, sized, maximised,
// minimised, z} persists settled geometry onto THIS user's open-window row. It mirrors close:
// the isOpen guard is the auth point (you may only persist geometry for YOUR open window), the
// fields are coerced server-side (ints/bools, never trust the wire), and it answers 204. Combined
// with the UPDATE-only saveGeometry (Task 1, S4), a forged/closed/other-user POST writes nothing.
class WmGeometryEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function principal(User $user): StateKey
    {
        return new StateKey('user', (string) $user->id, '');
    }

    public function test_geometry_persists_onto_an_open_window_row(): void
    {
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);
        $row = $service->launch($this->principal($user), 'notes');

        $this->actingAs($user)->postJson('/system-x/wm/geometry', [
            'window' => $row->window_id,
            'x' => 120,
            'y' => 80,
            'w' => 600,
            'h' => 400,
            'sized' => true,
            'maximised' => false,
            'minimised' => false,
            'z' => 7,
        ])->assertNoContent();

        // Read the geometry back off the row via the service -- it persisted.
        $window = collect($service->forPrincipal($this->principal($user)))
            ->firstWhere('window', $row->window_id);

        $this->assertSame(120, $window['x']);
        $this->assertSame(80, $window['y']);
        $this->assertSame(600, $window['w']);
        $this->assertSame(400, $window['h']);
        $this->assertTrue($window['sized']);
        $this->assertFalse($window['maximised']);
        $this->assertFalse($window['minimised']);
        $this->assertSame(7, $window['z']);
    }

    public function test_geometry_coerces_wire_types(): void
    {
        // The client may send string/loose values; the controller coerces ints/bools so the
        // row stores typed geometry, never raw wire strings.
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);
        $row = $service->launch($this->principal($user), 'notes');

        $this->actingAs($user)->postJson('/system-x/wm/geometry', [
            'window' => $row->window_id,
            'x' => '600',
            'y' => '300',
            'w' => '800',
            'h' => '500',
            'sized' => 1,
            'maximised' => 0,
            'minimised' => 0,
            'z' => '3',
        ])->assertNoContent();

        $window = collect($service->forPrincipal($this->principal($user)))
            ->firstWhere('window', $row->window_id);

        $this->assertSame(600, $window['x']);
        $this->assertSame(300, $window['y']);
        $this->assertSame(800, $window['w']);
        $this->assertSame(500, $window['h']);
        $this->assertTrue($window['sized']);
        $this->assertFalse($window['maximised']);
    }

    public function test_geometry_rejects_a_window_not_in_the_users_open_set(): void
    {
        // A forged / never-opened window id is not in this user's open-set -- the isOpen guard
        // 403s and writes nothing (mirrors close).
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/system-x/wm/geometry', [
            'window' => 'not-a-real-window',
            'x' => 10,
            'y' => 10,
            'w' => 100,
            'h' => 100,
            'sized' => true,
            'maximised' => false,
            'minimised' => false,
            'z' => 1,
        ])->assertStatus(403);
    }

    public function test_a_user_cannot_persist_geometry_for_another_users_window(): void
    {
        // The principal is ALWAYS the authed user; isOpen checks the authed user's set, so Bob
        // can't write onto Alice's row even with her window id. 403, no write.
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $service = app(OpenWindowService::class);
        $aliceRow = $service->launch($this->principal($alice), 'notes');

        $this->actingAs($bob)->postJson('/system-x/wm/geometry', [
            'window' => $aliceRow->window_id,
            'x' => 999,
            'y' => 999,
            'w' => 999,
            'h' => 999,
            'sized' => true,
            'maximised' => false,
            'minimised' => false,
            'z' => 999,
        ])->assertStatus(403);

        // Alice's row is untouched -- her geometry is still NULL (Bob's forged write hit nothing).
        $aliceWindow = collect($service->forPrincipal($this->principal($alice)))
            ->firstWhere('window', $aliceRow->window_id);
        $this->assertNull($aliceWindow['x']);
        $this->assertNull($aliceWindow['z']);
    }

    public function test_geometry_requires_auth(): void
    {
        $this->postJson('/system-x/wm/geometry', ['window' => 'whatever'])->assertUnauthorized();
    }
}

<?php

namespace Tests\Feature\Wm;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\State\StateBag;
use SystemX\Core\State\StateKey;
use SystemX\Core\State\StateStore;
use SystemX\Core\State\WindowState;
use SystemX\Core\Wm\OpenWindowService;
use Tests\TestCase;

// Task 9 (Plan 5a, D7): POST /system-x/wm/close {window} validates the window is in THIS
// user's open-set (the auth point), drops the open-row, and StateStore::forgets the bag.
// Explicit close FORGETS the durable state; a reload/disconnect RETAINS it (that's the
// restore path, untouched here). Cross-user safety: A may not close B's window.
class WmCloseTest extends TestCase
{
    use RefreshDatabase;

    private function principal(User $user): StateKey
    {
        return new StateKey('user', (string) $user->id, '');
    }

    public function test_close_drops_the_open_row_and_forgets_the_bag(): void
    {
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);
        $principal = $this->principal($user);
        $row = $service->launch($principal, 'notes');
        // Give the window a bag (as if it had been used).
        app(StateStore::class)->save(
            new StateKey('user', (string) $user->id, $row->window_id),
            new StateBag(['message' => 'hi'], 1),
        );
        $this->assertSame(1, WindowState::query()->where('window_id', $row->window_id)->count());

        $this->actingAs($user)->postJson('/system-x/wm/close', ['window' => $row->window_id])
            ->assertNoContent();

        // The open-row is gone -- the window is no longer in this user's set.
        $this->assertFalse($service->isOpen($principal, $row->window_id));
        // Explicit close FORGETS the bag (D7) -- the bag row is reaped too.
        $this->assertSame(0, WindowState::query()->where('window_id', $row->window_id)->count());
    }

    public function test_close_rejects_a_window_not_in_the_users_open_set(): void
    {
        // A forged / not-open window id touches no state -- the open-set membership check is
        // the auth point (you may only close YOUR open window).
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/system-x/wm/close', ['window' => 'not-a-real-window'])
            ->assertStatus(403);
    }

    public function test_a_user_cannot_close_another_users_window(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $service = app(OpenWindowService::class);
        $aliceRow = $service->launch($this->principal($alice), 'notes');
        // Give Alice's window a bag so we can prove it survives Bob's attempt.
        app(StateStore::class)->save(
            new StateKey('user', (string) $alice->id, $aliceRow->window_id),
            new StateBag(['message' => 'alice'], 1),
        );

        // Bob cannot close Alice's window (the open-set membership auth point).
        $this->actingAs($bob)->postJson('/system-x/wm/close', ['window' => $aliceRow->window_id])
            ->assertStatus(403);

        // Alice's row AND bag survive -- Bob's forged close reaped nothing.
        $this->assertTrue($service->isOpen($this->principal($alice), $aliceRow->window_id));
        $this->assertSame(1, WindowState::query()->where('window_id', $aliceRow->window_id)->count());
    }

    public function test_close_requires_auth(): void
    {
        $this->postJson('/system-x/wm/close', ['window' => 'whatever'])->assertUnauthorized();
    }
}

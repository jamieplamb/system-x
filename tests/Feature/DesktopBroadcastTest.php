<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use SystemX\Core\Events\DesktopRendered;
use SystemX\Core\State\DatabaseStateStore;
use SystemX\Core\State\StateBag;
use SystemX\Core\State\StateKey;
use SystemX\Core\State\StateStore;
use SystemX\Core\Wm\OpenWindowService;
use Tests\TestCase;

class DesktopBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_clicker_event_increments_the_stored_count_and_broadcasts(): void
    {
        Event::fake([DesktopRendered::class]);

        $user = User::factory()->create();

        // Seed the open-set so the tightened event() membership guard (Task 6, D7) lets
        // this POST window=hello through -- the static pair seeds hello+notes as slug-ids.
        app(OpenWindowService::class)->seedDefaults(
            new StateKey('user', (string) $user->id, ''),
        );

        // SEED the store: the count is now durable server state, NOT echoed up. Re-keyed
        // to the user principal (Task 4) -- the StateKey is keyed on the logged-in user id
        // and the wire window slug; the POST carries window=hello so the resolver finds
        // this row (the session fallback is gone, D5).
        $this->app->make(StateStore::class)->save(
            new StateKey('user', (string) $user->id, 'hello'),
            new StateBag(['count' => 4], DatabaseStateStore::SCHEMA_VERSION),
        );

        $this->actingAs($user)
            ->postJson('/system-x/event', [
                'widget' => 'clicker',
                'event' => 'click',
                'window' => 'hello',
                // NO state.count -- the client echoes no count; the window slug is the wire key.
            ])
            ->assertNoContent();

        // The server read 4 from the store, ++'d to 5, persisted it, and broadcast it.
        // The broadcast channel id is the user id now (the principal).
        Event::assertDispatched(DesktopRendered::class, function (DesktopRendered $e) use ($user): bool {
            return $e->desktopId === (string) $user->id
                && $e->tree['type'] === 'window'
                && $e->tree['children'][0]['props']['text'] === 'Clicked 5 times';
        });

        $this->assertSame(5, $this->app->make(StateStore::class)
            ->load(new StateKey('user', (string) $user->id, 'hello'))->get('count'));
    }

    public function test_a_guest_event_is_gate_rejected_and_does_not_broadcast(): void
    {
        Event::fake([DesktopRendered::class]);

        // A guest POST is now rejected by the auth gate (Task 3) BEFORE it reaches the
        // controller -- 401, no broadcast. (Pre-gate this asserted a 204 no-content bail
        // on the null-resolver guard; the gate now short-circuits it earlier.)
        $this->postJson('/system-x/event', [
            'widget' => 'clicker',
            'event' => 'click',
        ])->assertUnauthorized();

        Event::assertNotDispatched(DesktopRendered::class);
    }
}

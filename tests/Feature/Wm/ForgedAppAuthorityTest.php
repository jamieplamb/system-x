<?php

namespace Tests\Feature\Wm;

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

// B1 (BLOCKER): the open-set is the AUTHORITY for which app runs against a window's bag.
// event()/desktop() must NEVER trust the wire `app` for an EXISTING window -- a forged
// `app` would otherwise run the wrong App against the bag and corrupt durable state.
class ForgedAppAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private function principal(User $user): StateKey
    {
        return new StateKey('user', (string) $user->id, '');
    }

    /**
     * Open a notes window (ULID id, recorded app `notes`) for the user and seed its bag
     * with durable content that ONLY the notes app owns ({message, notify} -- no count).
     */
    private function openNotesWindowWithBag(User $user): string
    {
        $row = app(OpenWindowService::class)->launch($this->principal($user), 'notes');

        $this->app->make(StateStore::class)->save(
            new StateKey('user', (string) $user->id, $row->window_id),
            new StateBag(['message' => 'secret', 'notify' => true], DatabaseStateStore::SCHEMA_VERSION),
        );

        return $row->window_id;
    }

    public function test_a_forged_app_event_does_not_corrupt_the_recorded_apps_bag(): void
    {
        Event::fake([DesktopRendered::class]);

        $user = User::factory()->create();
        $windowId = $this->openNotesWindowWithBag($user);

        // The attack: this user owns the notes window (membership passes), but they POST
        // app=hello against it. If the controller trusts the wire app, HelloApp runs and
        // overwrites the notes bag with {count:0}, destroying {message, notify}.
        $this->actingAs($user)
            ->postJson('/system-x/event', [
                'widget' => 'clicker',
                'event' => 'click',
                'window' => $windowId,
                'app' => 'hello',
            ])
            ->assertNoContent();

        // CRUCIAL: the durable notes content survives -- no `count` was ever written.
        $bag = $this->app->make(StateStore::class)
            ->load(new StateKey('user', (string) $user->id, $windowId));

        $this->assertSame('secret', $bag->get('message'));
        $this->assertTrue($bag->get('notify'));
        $this->assertNull($bag->get('count'));

        // Any broadcast that DID fire must carry the RECORDED app (notes), never the
        // forged wire app -- a frame can't claim the wrong app for a window.
        Event::assertNotDispatched(
            DesktopRendered::class,
            fn (DesktopRendered $e): bool => $e->appSlug === 'hello',
        );
    }

    public function test_a_forged_app_desktop_resync_renders_the_recorded_app_tree(): void
    {
        $user = User::factory()->create();
        $windowId = $this->openNotesWindowWithBag($user);

        // The read-only twin: ?window=<notes-ulid>&app=hello must render the NOTES tree
        // (recorded app), not hello's. The notes preview label echoes the durable message.
        $this->actingAs($user)
            ->getJson("/system-x/desktop?window={$windowId}&app=hello")
            ->assertOk()
            ->assertJsonPath('props.title', 'Notes')
            ->assertJsonPath('children.2.props.text', 'Note: secret (notify on)');
    }

    public function test_the_legitimate_matching_app_event_still_runs_notes(): void
    {
        Event::fake([DesktopRendered::class]);

        $user = User::factory()->create();
        $windowId = $this->openNotesWindowWithBag($user);

        // The honest path: app=notes (the recorded app) submits the message field. The
        // notes app must run and update the durable message.
        $this->actingAs($user)
            ->postJson('/system-x/event', [
                'widget' => 'message-field',
                'event' => 'submit',
                'value' => 'updated',
                'window' => $windowId,
                'app' => 'notes',
            ])
            ->assertNoContent();

        $bag = $this->app->make(StateStore::class)
            ->load(new StateKey('user', (string) $user->id, $windowId));

        $this->assertSame('updated', $bag->get('message'));
        $this->assertTrue($bag->get('notify')); // untouched

        Event::assertDispatched(
            DesktopRendered::class,
            fn (DesktopRendered $e): bool => $e->appSlug === 'notes'
                && $e->tree['children'][2]['props']['text'] === 'Note: updated (notify on)',
        );
    }

    public function test_an_event_with_no_wire_app_runs_the_recorded_app(): void
    {
        Event::fake([DesktopRendered::class]);

        $user = User::factory()->create();
        $windowId = $this->openNotesWindowWithBag($user);

        // No wire app at all -- the open-set is the sole authority. Notes must still run.
        $this->actingAs($user)
            ->postJson('/system-x/event', [
                'widget' => 'notify-toggle',
                'event' => 'change',
                'value' => false,
                'window' => $windowId,
            ])
            ->assertNoContent();

        $bag = $this->app->make(StateStore::class)
            ->load(new StateKey('user', (string) $user->id, $windowId));

        $this->assertSame('secret', $bag->get('message')); // untouched
        $this->assertFalse($bag->get('notify'));            // toggled off

        Event::assertDispatched(
            DesktopRendered::class,
            fn (DesktopRendered $e): bool => $e->appSlug === 'notes',
        );
    }

    public function test_an_event_on_a_window_not_in_the_users_open_set_is_dropped(): void
    {
        Event::fake([DesktopRendered::class]);

        $alice = User::factory()->create();
        $bob = User::factory()->create();

        // Alice opens a notes window; Bob tries to drive it. It is not in Bob's open-set,
        // so appFor returns null and the event is dropped -- no write, no broadcast.
        $aliceWindow = $this->openNotesWindowWithBag($alice);

        $this->actingAs($bob)
            ->postJson('/system-x/event', [
                'widget' => 'message-field',
                'event' => 'submit',
                'value' => 'pwned',
                'window' => $aliceWindow,
                'app' => 'notes',
            ])
            ->assertNoContent();

        // Alice's bag is untouched.
        $bag = $this->app->make(StateStore::class)
            ->load(new StateKey('user', (string) $alice->id, $aliceWindow));
        $this->assertSame('secret', $bag->get('message'));

        Event::assertNotDispatched(DesktopRendered::class);
    }
}

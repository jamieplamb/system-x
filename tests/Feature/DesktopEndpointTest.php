<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use SystemX\Core\Events\DesktopRendered;
use SystemX\Core\State\DatabaseStateStore;
use SystemX\Core\State\StateBag;
use SystemX\Core\State\StateKey;
use SystemX\Core\State\StateStore;
use SystemX\Core\State\WindowState;
use SystemX\Core\Wm\OpenWindowService;
use Tests\TestCase;

class DesktopEndpointTest extends TestCase
{
    use RefreshDatabase;

    // Seed the static pair into the acting user's open-set so the tightened event()
    // membership guard (Task 6, D7) lets a POST window=hello through, and the desktop()
    // resync resolves the slug-as-id window's app. Mirrors the shell's first-boot seed.
    private function seedOpenWindows(User $user): void
    {
        app(OpenWindowService::class)->seedDefaults(
            new StateKey('user', (string) $user->id, ''),
        );
    }

    public function test_desktop_returns_the_initial_window_tree_at_the_stored_count(): void
    {
        // No stored bag yet -> default count 0 (store default, not a hardcoded window(0)).
        // Route is now auth-gated (Task 3): log a user in or the gate 401s before the
        // controller. The resolver still keys on the session until Task 4.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/system-x/desktop')
            ->assertOk()
            ->assertJsonPath('type', 'window')
            ->assertJsonPath('children.0.props.text', 'Clicked 0 times');
    }

    public function test_desktop_renders_the_persisted_count_for_a_known_desktop(): void
    {
        $user = User::factory()->create();

        // Re-keyed to the user principal (Task 4): the bag is keyed on the logged-in user
        // id + the wire window slug. The GET carries ?window=hello (the resync the resolver
        // reads through input('window')); the session fallback is gone (D5), so the window
        // MUST be on the wire for the resolver to find this row.
        $this->seedOpenWindows($user);
        $this->app->make(StateStore::class)->save(
            new StateKey('user', (string) $user->id, 'hello'),
            new StateBag(['count' => 3], DatabaseStateStore::SCHEMA_VERSION),
        );

        $this->actingAs($user)
            ->getJson('/system-x/desktop?window=hello')
            ->assertOk()
            ->assertJsonPath('children.0.props.text', 'Clicked 3 times');
    }

    public function test_desktop_renders_the_persisted_count_for_the_window_off_the_query_string(): void
    {
        $user = User::factory()->create();

        // The window-scoped resync GET (B3): ?window=hello keys the bag via input('window')
        // -- the SAME accessor a POST reads off the body -- with NO session sx_window_id at
        // all (the shell mints none). The seeded slug-keyed bag must come back, proving each
        // static window resyncs its OWN bag through the query string.
        $this->seedOpenWindows($user);
        $this->app->make(StateStore::class)->save(
            new StateKey('user', (string) $user->id, 'hello'),
            new StateBag(['count' => 6], DatabaseStateStore::SCHEMA_VERSION),
        );

        $this->actingAs($user)
            ->getJson('/system-x/desktop?window=hello')
            ->assertOk()
            ->assertJsonPath('children.0.props.text', 'Clicked 6 times');
    }

    public function test_an_authed_desktop_get_with_no_window_renders_the_default_empty_tree(): void
    {
        // D5 dropped the session window-id fallback, so a no-`window` GET resolves a
        // null key and falls to renderFromBag('hello', []) -- a 200 empty hello tree.
        // This is INTENDED (harmless: no state read, no cross-user leak), not a bug;
        // every real client sends ?window={slug}, this is the defensive default.
        $this->actingAs(User::factory()->create())
            ->getJson('/system-x/desktop')
            ->assertOk()
            ->assertJsonPath('children.0.props.text', 'Clicked 0 times');
    }

    public function test_event_acks_and_broadcasts_the_stored_count_plus_one(): void
    {
        Event::fake([DesktopRendered::class]);

        $user = User::factory()->create();

        $this->seedOpenWindows($user);
        $this->app->make(StateStore::class)->save(
            new StateKey('user', (string) $user->id, 'hello'),
            new StateBag(['count' => 4], DatabaseStateStore::SCHEMA_VERSION),
        );

        $this->actingAs($user)
            ->postJson('/system-x/event', [
                'widget' => 'clicker',
                'event' => 'click',
                'window' => 'hello',
            ])
            ->assertNoContent();

        Event::assertDispatched(
            DesktopRendered::class,
            fn (DesktopRendered $e): bool => $e->tree['children'][0]['props']['text'] === 'Clicked 5 times',
        );
    }

    public function test_a_first_event_on_a_fresh_key_increments_to_one_and_creates_exactly_one_row(): void
    {
        Event::fake([DesktopRendered::class]);

        $user = User::factory()->create();

        $this->seedOpenWindows($user);

        // No row exists yet for this key. Under the old lockForUpdate()->first() the
        // lock would grab nothing on this very first event; the row is now pre-created
        // inside the transaction so the lock always has a real row to serialise on.
        $this->actingAs($user)
            ->postJson('/system-x/event', [
                'widget' => 'clicker',
                'event' => 'click',
                'window' => 'hello',
            ])
            ->assertNoContent();

        // Exactly one row, at count 1, stamped with the CURRENT schema version and the
        // default bag shape -- shape-identical to what save() writes. Keyed on the user
        // principal now (Task 4): principal_type='user', principal_id=user id, window=wire slug.
        $this->assertSame(1, WindowState::query()->count());

        $row = WindowState::query()
            ->where('principal_type', 'user')
            ->where('principal_id', (string) $user->id)
            ->where('window_id', 'hello')
            ->firstOrFail();

        $this->assertSame(1, $row->bag['count']);
        $this->assertSame(DatabaseStateStore::SCHEMA_VERSION, $row->schema_version);

        // The broadcast carries the incremented count, proving the first-event path
        // read the (pre-created) row and incremented it rather than losing it.
        Event::assertDispatched(
            DesktopRendered::class,
            fn (DesktopRendered $e): bool => $e->tree['children'][0]['props']['text'] === 'Clicked 1 times',
        );
    }

    public function test_event_with_an_unregistered_window_bails_204_without_writing_or_broadcasting(): void
    {
        Event::fake([DesktopRendered::class]);

        $user = User::factory()->create();

        // A crafted POST: valid booted session, but a `window` slug no real client ever
        // sends. The guard must bail BEFORE the txn/lock/broadcast rather than entering
        // the kernel and 500ing on AppRegistry::resolve's InvalidArgumentException.
        $this->actingAs($user)
            ->postJson('/system-x/event', [
                'widget' => 'clicker',
                'event' => 'click',
                'window' => 'garbage',
            ])
            ->assertNoContent();

        // No broadcast for a dropped request...
        Event::assertNotDispatched(DesktopRendered::class);

        // ...and no row written (the bail is before the firstOrCreate pre-create).
        $this->assertSame(0, WindowState::query()->count());
    }

    public function test_desktop_get_for_an_unregistered_window_404s(): void
    {
        $user = User::factory()->create();

        // A GET resync for a window/app that doesn't exist. A real client only ever
        // requests hello/notes; anything else is a 404, not a 500. A guest now 401s
        // BEFORE the 404 slug guard, so we log in to reach the 404 path.
        $this->actingAs($user)
            ->getJson('/system-x/desktop?window=garbage')
            ->assertNotFound();
    }

    public function test_event_post_without_a_csrf_token_is_rejected_419(): void
    {
        // The event endpoint mutates the authenticated user's durable state under the
        // session cookie, so it MUST be CSRF-protected (4c removed the system-x/* CSRF
        // exemption). Laravel auto-skips CSRF in tests (runningUnitTests()), so we bind
        // a subclass that forces the check on, then prove a tokenless cross-site-style
        // POST is rejected before it can write. The real-browser proof is Dusk (the JS
        // sends X-CSRF-TOKEN); this guards the negative path the suite can't see otherwise.
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        $user = User::factory()->create();
        $this->seedOpenWindows($user);

        $this->actingAs($user)
            ->post('/system-x/event', [
                'widget' => 'clicker',
                'event' => 'click',
                'window' => 'hello',
            ])
            ->assertStatus(419);

        $this->assertSame(0, WindowState::query()->count());
    }

    public function test_a_second_event_on_the_same_fresh_key_increments_to_two(): void
    {
        Event::fake([DesktopRendered::class]);

        $user = User::factory()->create();
        $this->seedOpenWindows($user);

        $event = ['widget' => 'clicker', 'event' => 'click', 'window' => 'hello'];

        $this->actingAs($user)
            ->postJson('/system-x/event', $event)
            ->assertNoContent();

        $this->actingAs($user)
            ->postJson('/system-x/event', $event)
            ->assertNoContent();

        // Still exactly one row (pre-create is firstOrCreate, not a duplicate insert),
        // now at count 2. Keyed on the user principal + the wire window slug (Task 4).
        $this->assertSame(1, WindowState::query()->count());
        $this->assertSame(2, $this->app->make(StateStore::class)
            ->load(new StateKey('user', (string) $user->id, 'hello'))
            ->get('count'));
    }
}

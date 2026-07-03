<?php

namespace Tests\Feature\Wm;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use SystemX\Core\Events\DesktopRendered;
use SystemX\Core\State\StateKey;
use SystemX\Core\Wm\OpenWindowService;
use Tests\TestCase;

// Task 6: the {app, window} identity split + the open-set membership guard. The
// controller now reads `app` (the slug) SEPARATELY from `window` (the instance id), and
// event() drops any POST whose window is not in the acting user's open-set.
class WindowIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function principal(User $user): StateKey
    {
        return new StateKey('user', (string) $user->id, '');
    }

    public function test_event_for_an_unopened_window_is_dropped_204_without_broadcasting(): void
    {
        Event::fake([DesktopRendered::class]);

        $user = User::factory()->create();
        // No open-window row for hello -> the membership guard must drop the POST even
        // though `hello` is a registered app. This is the auth point: "may this user
        // touch this window".
        $this->actingAs($user)
            ->postJson('/system-x/event', [
                'widget' => 'clicker',
                'event' => 'click',
                'app' => 'hello',
                'window' => 'hello',
            ])
            ->assertNoContent();

        Event::assertNotDispatched(DesktopRendered::class);
    }

    public function test_event_for_an_open_window_passes_the_membership_guard_and_broadcasts(): void
    {
        Event::fake([DesktopRendered::class]);

        $user = User::factory()->create();
        app(OpenWindowService::class)->seedDefaults($this->principal($user));

        $this->actingAs($user)
            ->postJson('/system-x/event', [
                'widget' => 'clicker',
                'event' => 'click',
                'app' => 'hello',
                'window' => 'hello',
            ])
            ->assertNoContent();

        Event::assertDispatched(DesktopRendered::class);
    }

    public function test_event_resolves_the_app_from_the_window_when_app_is_absent(): void
    {
        // Back-compat: a POST that carries only `window=hello` (slug-as-id) still resolves
        // the app via the input('window') fallback, and the seeded open-row lets it pass.
        Event::fake([DesktopRendered::class]);

        $user = User::factory()->create();
        app(OpenWindowService::class)->seedDefaults($this->principal($user));

        $this->actingAs($user)
            ->postJson('/system-x/event', [
                'widget' => 'clicker',
                'event' => 'click',
                'window' => 'hello',
            ])
            ->assertNoContent();

        Event::assertDispatched(DesktopRendered::class);
    }

    public function test_event_uses_the_app_to_run_a_launched_ulid_window(): void
    {
        // A launched ULID window addresses its bag by the ULID, but runs the `notes` app.
        // The POST carries app=notes + window=<ulid>; the membership guard passes (it's
        // open) and the broadcast addresses the ULID window.
        Event::fake([DesktopRendered::class]);

        $user = User::factory()->create();
        $service = app(OpenWindowService::class);
        $row = $service->launch($this->principal($user), 'notes');

        $this->actingAs($user)
            ->postJson('/system-x/event', [
                'widget' => 'whatever',
                'event' => 'change',
                'value' => 'hi',
                'app' => 'notes',
                'window' => $row->window_id,
            ])
            ->assertNoContent();

        Event::assertDispatched(
            DesktopRendered::class,
            fn (DesktopRendered $e): bool => $e->windowId === $row->window_id && $e->appSlug === 'notes',
        );
    }

    public function test_desktop_resync_resolves_the_app_from_the_open_set_when_app_absent(): void
    {
        // B4: the resync GET sends only ?window=<id>. A launched ULID window has no slug
        // to fall back to, so desktop() resolves its app from the open-set.
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);
        $row = $service->launch($this->principal($user), 'notes');

        $this->actingAs($user)
            ->getJson('/system-x/desktop?window='.$row->window_id)
            ->assertOk()
            ->assertJsonPath('type', 'window');
    }

    public function test_desktop_resync_for_a_window_not_open_for_the_user_404s(): void
    {
        // N4: a present-but-not-open window resolves no app -> 404 before any render, so a
        // forged ?window=<not-open> can't even probe.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/system-x/desktop?window=01HZZZZZZZZZZZZZZZZZZZZZZZ')
            ->assertNotFound();
    }

    public function test_keyless_desktop_get_still_returns_the_default_tree(): void
    {
        // The keyless early-return is preserved: a no-window GET resolves a null key and
        // falls to the hello-default 200, NOT a 404.
        $this->actingAs(User::factory()->create())
            ->getJson('/system-x/desktop')
            ->assertOk()
            ->assertJsonPath('children.0.props.text', 'Clicked 0 times');
    }
}

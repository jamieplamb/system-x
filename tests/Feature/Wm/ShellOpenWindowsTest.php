<?php

namespace Tests\Feature\Wm;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Preferences\PreferencesService;
use SystemX\Core\State\StateKey;
use SystemX\Core\Wm\OpenWindow;
use SystemX\Core\Wm\OpenWindowService;
use Tests\TestCase;

class ShellOpenWindowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shell_renders_the_users_open_windows_seeding_defaults_on_first_boot(): void
    {
        // The shell no longer hardcodes the pair -- it seeds the open set on first boot
        // (idempotent) and renders THIS user's open-window rows. A fresh user therefore
        // gets the seeded hello+notes surfaces (slug-as-id, D4/D7).
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('data-window-id="hello"', false)
            ->assertSee('data-window-id="notes"', false)
            ->assertSee('data-app="hello"', false);

        // First boot SEEDED the open set -- the rows now exist.
        $this->assertSame(2, OpenWindow::query()->where('principal_id', (string) $user->id)->count());

        // ...and the user is now MARKED seeded -- the once-ever gate has fired (so a future
        // empty desktop stays empty rather than re-seeding).
        $principal = new StateKey('user', (string) $user->id, '');
        $this->assertTrue(app(PreferencesService::class)->hasSeededDesktop($principal));
    }

    public function test_an_already_seeded_user_with_an_empty_desktop_does_not_get_re_seeded(): void
    {
        // THE BUG-FIX PROOF (load-bearing). A user who has already been seeded ONCE, then
        // closed EVERY window, must keep an empty desktop across refreshes -- real-OS
        // behaviour. The old "re-seed when the set is empty" logic broke this; the once-ever
        // marker fixes it.
        $user = User::factory()->create();
        $principal = new StateKey('user', (string) $user->id, '');

        // Mark them seeded WITHOUT opening any windows -- the empty-but-already-seeded state.
        app(PreferencesService::class)->markDesktopSeeded($principal);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertDontSee('data-window-id="hello"', false)
            ->assertDontSee('data-window-id="notes"', false);

        // No windows were re-seeded -- the empty set stayed empty.
        $this->assertSame(0, OpenWindow::query()->where('principal_id', (string) $user->id)->count());
    }

    public function test_a_second_boot_after_closing_everything_does_not_re_seed(): void
    {
        // Idempotence end-to-end: first GET / seeds the pair; then the user closes everything
        // (delete their open rows); a SECOND GET / must NOT bring the pair back.
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertOk();
        $this->assertSame(2, OpenWindow::query()->where('principal_id', (string) $user->id)->count());

        // The user closes every window.
        OpenWindow::query()->where('principal_id', (string) $user->id)->delete();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertDontSee('data-window-id="hello"', false)
            ->assertDontSee('data-window-id="notes"', false);

        $this->assertSame(0, OpenWindow::query()->where('principal_id', (string) $user->id)->count());
    }

    public function test_a_window_outside_the_static_pair_renders_proving_the_source_is_the_open_set(): void
    {
        // The discriminator: a user whose open set is NOT the hardcoded hello+notes pair.
        // If the shell still rendered a constant array this would FAIL -- only reading the
        // user's actual open rows surfaces this ULID-keyed window. (Its bag stays empty; we
        // assert the SURFACE renders, not its content.)
        $user = User::factory()->create();
        $principal = new StateKey('user', (string) $user->id, '');
        $row = app(OpenWindowService::class)->launch($principal, 'notes');

        $this->actingAs($user)->get('/')
            ->assertSee('data-window-id="'.$row->window_id.'"', false)
            ->assertSee('data-app="notes"', false)
            // The hardcoded pair's slugs are NOT this user's open set, so they must NOT leak.
            ->assertDontSee('data-window-id="hello"', false);
    }
}

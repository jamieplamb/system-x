<?php

namespace Tests\Feature\Wm;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use SystemX\Core\State\StateKey;
use SystemX\Core\Wm\OpenWindow;
use SystemX\Core\Wm\OpenWindowService;
use Tests\TestCase;

class OpenWindowServiceTest extends TestCase
{
    use RefreshDatabase;

    private function principal(User $user): StateKey
    {
        // The service keys on (principalType, principalId) -- the SAME shape as the bag
        // key (4a/4c). windowId is irrelevant for the open-SET; pass a placeholder.
        return new StateKey('user', (string) $user->id, '');
    }

    public function test_seed_defaults_opens_hello_and_notes_for_a_fresh_user(): void
    {
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);

        $service->seedDefaults($this->principal($user));

        $windows = collect($service->forPrincipal($this->principal($user)));
        $this->assertEqualsCanonicalizing(['hello', 'notes'], $windows->pluck('window')->all());
        // The static pair KEEPS its slug as the window id (D4 -- zero data migration).
        $this->assertEqualsCanonicalizing(['hello', 'notes'], $windows->pluck('app')->all());
    }

    public function test_seed_defaults_is_idempotent(): void
    {
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);

        $service->seedDefaults($this->principal($user));
        $service->seedDefaults($this->principal($user)); // second call is a no-op

        $this->assertCount(2, $service->forPrincipal($this->principal($user)));
    }

    public function test_launch_mints_a_ulid_window_for_the_app(): void
    {
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);

        $row = $service->launch($this->principal($user), 'notes');

        $this->assertSame('notes', $row->app);
        $this->assertNotSame('notes', $row->window_id);   // a ULID, not the slug
        $this->assertSame(26, strlen($row->window_id));    // ULID length
        $this->assertTrue($service->isOpen($this->principal($user), $row->window_id));
    }

    public function test_launch_is_singleton_per_app_a_second_launch_returns_the_same_window(): void
    {
        // S4: 5a apps are singletons -- a second launch of the same app must NOT mint a
        // second ULID row (a direct POST loop must stay bounded). It returns the existing
        // window; the duplicate-spawn launcher UX is 5b.
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);

        $first = $service->launch($this->principal($user), 'notes');
        $second = $service->launch($this->principal($user), 'notes');

        $this->assertSame($first->window_id, $second->window_id); // same window, no second row
        $this->assertCount(1, $service->forPrincipal($this->principal($user)));
    }

    public function test_close_drops_the_row_and_is_no_longer_open(): void
    {
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);
        $row = $service->launch($this->principal($user), 'notes');

        $service->close($this->principal($user), $row->window_id);

        $this->assertFalse($service->isOpen($this->principal($user), $row->window_id));
    }

    public function test_app_for_resolves_the_open_windows_app(): void
    {
        // B4: the resync GET's app-resolution read point -- which app renders into this
        // open window for this user. Returns null when the window isn't open for them.
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);
        $service->seedDefaults($this->principal($user));
        $row = $service->launch($this->principal($user), 'notes');

        $this->assertSame('hello', $service->appFor($this->principal($user), 'hello'));
        $this->assertSame('notes', $service->appFor($this->principal($user), $row->window_id));
        $this->assertNull($service->appFor($this->principal($user), 'not-open'));
    }

    public function test_the_database_enforces_singleton_per_app_even_if_app_code_races(): void
    {
        // S1: launch()'s firstOrCreate on (principal, app) is read-then-insert, NOT atomic.
        // Two concurrent launches can both miss the existing row and both INSERT a fresh
        // ULID, defeating the bounded-singleton guarantee. The migration's UNIQUE index on
        // (principal_type, principal_id, app) is what makes the losing INSERT throw -- so
        // firstOrCreate reads through instead of duplicating. Prove the DB enforces it: a
        // SECOND raw row for the same (user, app) with a DIFFERENT window_id must be rejected.
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);

        $first = $service->launch($this->principal($user), 'notes');

        $this->expectException(QueryException::class);

        OpenWindow::query()->create([
            'principal_type' => 'user',
            'principal_id' => (string) $user->id,
            'window_id' => (string) Str::ulid(), // a DIFFERENT window id, same (user, app)
            'app' => 'notes',
        ]);

        // Sanity: still exactly the one window the constraint allows.
        $this->assertNotNull($first->window_id);
    }

    public function test_one_users_open_set_never_includes_anothers(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $service = app(OpenWindowService::class);

        $aliceRow = $service->launch($this->principal($alice), 'notes');

        $this->assertFalse($service->isOpen($this->principal($bob), $aliceRow->window_id));
        $this->assertCount(0, $service->forPrincipal($this->principal($bob)));
    }

    public function test_save_geometry_persists_the_rect_flags_and_z_onto_the_row(): void
    {
        // 5e D1/D2: geometry EXTENDS the open-windows row. saveGeometry writes the restore
        // rect + the maximised/minimised flags + the stacking z onto the existing row.
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);
        $row = $service->launch($this->principal($user), 'notes');

        $service->saveGeometry($this->principal($user), $row->window_id, [
            'x' => 120,
            'y' => 64,
            'w' => 640,
            'h' => 480,
            'sized' => true,
            'maximised' => true,
            'minimised' => false,
            'z' => 7,
        ]);

        $fresh = $row->fresh();
        $this->assertSame(120, $fresh->x);
        $this->assertSame(64, $fresh->y);
        $this->assertSame(640, $fresh->w);
        $this->assertSame(480, $fresh->h);
        $this->assertTrue($fresh->sized);
        $this->assertTrue($fresh->maximised);
        $this->assertFalse($fresh->minimised);
        $this->assertSame(7, $fresh->z);
    }

    public function test_for_principal_returns_the_geometry_fields_per_window(): void
    {
        // forPrincipal must carry geometry so the boot route can stamp it onto the surfaces.
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);
        $row = $service->launch($this->principal($user), 'notes');

        $service->saveGeometry($this->principal($user), $row->window_id, [
            'x' => 10,
            'y' => 20,
            'w' => 300,
            'h' => 200,
            'sized' => true,
            'maximised' => false,
            'minimised' => true,
            'z' => 3,
        ]);

        $window = collect($service->forPrincipal($this->principal($user)))->firstWhere('window', $row->window_id);

        // The existing keys still ride along (the ...$w spread + the metadata join depend on them).
        $this->assertSame($row->window_id, $window['window']);
        $this->assertSame('notes', $window['app']);
        // The geometry fields are present + carry the saved values.
        $this->assertSame(10, $window['x']);
        $this->assertSame(20, $window['y']);
        $this->assertSame(300, $window['w']);
        $this->assertSame(200, $window['h']);
        $this->assertTrue($window['sized']);
        $this->assertFalse($window['maximised']);
        $this->assertTrue($window['minimised']);
        $this->assertSame(3, $window['z']);
    }

    public function test_a_freshly_launched_window_has_null_geometry(): void
    {
        // A row is born on launch with NULL geometry (the INSERT path never sets it) -- the
        // client cascades it on first appearance, then persists on settle.
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);
        $row = $service->launch($this->principal($user), 'notes');

        $fresh = $row->fresh();
        $this->assertNull($fresh->x);
        $this->assertNull($fresh->y);
        $this->assertNull($fresh->w);
        $this->assertNull($fresh->h);
        $this->assertNull($fresh->z);
        // The bool flags default to false, never null.
        $this->assertFalse($fresh->sized);
        $this->assertFalse($fresh->maximised);
        $this->assertFalse($fresh->minimised);

        $window = collect($service->forPrincipal($this->principal($user)))->firstWhere('window', $row->window_id);
        $this->assertNull($window['x']);
        $this->assertNull($window['y']);
        $this->assertNull($window['w']);
        $this->assertNull($window['h']);
        $this->assertNull($window['z']);
        $this->assertFalse($window['sized']);
        $this->assertFalse($window['maximised']);
        $this->assertFalse($window['minimised']);
    }

    public function test_close_deletes_the_row_so_geometry_is_gone_with_no_orphan(): void
    {
        // Geometry is 1:1 with the open-set because it IS the row -- close hard-deletes it,
        // so geometry cleans up for free (no orphan, no separate forget()).
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);
        $row = $service->launch($this->principal($user), 'notes');
        $service->saveGeometry($this->principal($user), $row->window_id, [
            'x' => 1, 'y' => 2, 'w' => 3, 'h' => 4, 'z' => 5,
        ]);

        $service->close($this->principal($user), $row->window_id);

        $this->assertSame(0, OpenWindow::query()
            ->where('principal_type', 'user')
            ->where('principal_id', (string) $user->id)
            ->where('window_id', $row->window_id)
            ->count());
    }

    public function test_save_geometry_on_a_closed_window_updates_zero_rows_and_never_inserts(): void
    {
        // S4 -- the close-race guard (the load-bearing test). saveGeometry is UPDATE-ONLY:
        // a fire-and-forget geometry POST that races a close must NOT resurrect the deleted
        // row. Save geometry for a window that was closed -> zero rows affected, no INSERT.
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);
        $row = $service->launch($this->principal($user), 'notes');
        $service->close($this->principal($user), $row->window_id);

        $service->saveGeometry($this->principal($user), $row->window_id, [
            'x' => 1, 'y' => 2, 'w' => 3, 'h' => 4, 'z' => 5,
        ]);

        $this->assertFalse($service->isOpen($this->principal($user), $row->window_id));
        $this->assertSame(0, OpenWindow::query()
            ->where('principal_type', 'user')
            ->where('principal_id', (string) $user->id)
            ->where('window_id', $row->window_id)
            ->count());
    }

    public function test_save_geometry_on_a_never_existent_window_inserts_nothing(): void
    {
        // S4 again -- a forged/never-existed window id must not be INSERTed by saveGeometry.
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);

        $service->saveGeometry($this->principal($user), 'never-existed', [
            'x' => 1, 'y' => 2, 'w' => 3, 'h' => 4, 'z' => 5,
        ]);

        $this->assertSame(0, OpenWindow::query()
            ->where('principal_type', 'user')
            ->where('principal_id', (string) $user->id)
            ->count());
    }
}

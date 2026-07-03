<?php

namespace Tests\Feature\Apps;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Apps\Installs\AppInstallService;
use SystemX\Core\Apps\Installs\UninstalledApp;
use SystemX\Core\State\StateBag;
use SystemX\Core\State\StateKey;
use SystemX\Core\State\StateStore;
use SystemX\Core\State\WindowState;
use SystemX\Core\Wm\OpenWindowService;
use Tests\TestCase;

// Task 3 (App-install plan, D3): POST /system-x/app/uninstall {app} ATOMICALLY closes the
// user's open windows of the app + forgets their state + marks the app uninstalled, in a
// single DB::transaction (close+forget BEFORE the mark). POST /system-x/app/install {app}
// unmarks. A system app can NEVER be uninstalled (403); an unregistered app 422s. The launch
// endpoint 403s a forged launch of an uninstalled non-system app (the server enforcement).
class AppInstallEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function principal(User $user): StateKey
    {
        return new StateKey('user', (string) $user->id, '');
    }

    public function test_uninstall_closes_the_apps_windows_forgets_state_and_marks_uninstalled(): void
    {
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);
        $principal = $this->principal($user);

        // The user has an OPEN hello window with some durable state.
        $row = $service->launch($principal, 'hello');
        app(StateStore::class)->save(
            new StateKey('user', (string) $user->id, $row->window_id),
            new StateBag(['count' => 3], 1),
        );
        $this->assertSame(1, WindowState::query()->where('window_id', $row->window_id)->count());

        $this->actingAs($user)->postJson('/system-x/app/uninstall', ['app' => 'hello'])
            ->assertNoContent();

        // The app is marked uninstalled.
        $this->assertTrue(app(AppInstallService::class)->isUninstalled($principal, 'hello'));
        // The open-set row is GONE (the raw close ran).
        $this->assertFalse($service->isOpen($principal, $row->window_id));
        // The state bag is FORGOTTEN.
        $this->assertSame(0, WindowState::query()->where('window_id', $row->window_id)->count());
    }

    public function test_uninstall_cleanup_and_mark_land_together_as_one_transaction(): void
    {
        // S3 -- the end state proves the order: NO hello windows AND uninstalled true, all in
        // one transaction (a partial failure can't mark-then-leave-windows).
        $user = User::factory()->create();
        $service = app(OpenWindowService::class);
        $principal = $this->principal($user);
        $service->launch($principal, 'hello');

        $this->actingAs($user)->postJson('/system-x/app/uninstall', ['app' => 'hello'])
            ->assertNoContent();

        // No hello window survives.
        $helloWindows = collect($service->forPrincipal($principal))
            ->where('app', 'hello')
            ->all();
        $this->assertSame([], $helloWindows);
        // AND it's marked uninstalled -- the two halves committed together.
        $this->assertTrue(app(AppInstallService::class)->isUninstalled($principal, 'hello'));
    }

    public function test_install_unmarks_the_app(): void
    {
        $user = User::factory()->create();
        $principal = $this->principal($user);
        app(AppInstallService::class)->uninstall($principal, 'hello');

        $this->actingAs($user)->postJson('/system-x/app/install', ['app' => 'hello'])
            ->assertNoContent();

        $this->assertFalse(app(AppInstallService::class)->isUninstalled($principal, 'hello'));
    }

    public function test_a_system_app_cannot_be_uninstalled(): void
    {
        // Can't uninstall furniture (D6): appearance is a system app -> 403, no row added.
        $user = User::factory()->create();
        $principal = $this->principal($user);

        $this->actingAs($user)->postJson('/system-x/app/uninstall', ['app' => 'appearance'])
            ->assertStatus(403);

        $this->assertFalse(app(AppInstallService::class)->isUninstalled($principal, 'appearance'));
        $this->assertSame(0, UninstalledApp::query()
            ->where('principal_id', (string) $user->id)
            ->where('app', 'appearance')
            ->count());
    }

    public function test_uninstall_rejects_an_unregistered_app(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/system-x/app/uninstall', ['app' => 'ghost'])
            ->assertStatus(422);
    }

    public function test_install_rejects_an_unregistered_app(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/system-x/app/install', ['app' => 'ghost'])
            ->assertStatus(422);
    }

    public function test_uninstall_requires_auth(): void
    {
        $this->postJson('/system-x/app/uninstall', ['app' => 'hello'])->assertUnauthorized();
    }

    public function test_install_requires_auth(): void
    {
        $this->postJson('/system-x/app/install', ['app' => 'hello'])->assertUnauthorized();
    }

    public function test_launch_is_blocked_for_an_uninstalled_non_system_app(): void
    {
        // The launch GUARD (D3): a forged launch of an uninstalled user app 403s server-side,
        // and mints NO open-row (the guard fires BEFORE the firstOrCreate).
        $user = User::factory()->create();
        $principal = $this->principal($user);
        app(AppInstallService::class)->uninstall($principal, 'hello');

        $this->actingAs($user)->postJson('/system-x/wm/launch', ['app' => 'hello'])
            ->assertStatus(403);

        $this->assertSame([], $this->openSlugsFor($principal));
    }

    public function test_launch_still_works_for_an_installed_app(): void
    {
        // The guard is additive: an installed user app still launches (200, a window minted).
        $user = User::factory()->create();

        $res = $this->actingAs($user)->postJson('/system-x/wm/launch', ['app' => 'hello'])
            ->assertOk()
            ->assertJsonPath('app', 'hello');

        $this->assertTrue(app(OpenWindowService::class)->isOpen($this->principal($user), $res->json('window')));
    }

    public function test_launch_works_for_a_system_app_even_if_somehow_uninstalled(): void
    {
        // System apps SKIP the guard (D6): Appearance/About/Manage-apps must ALWAYS launch from
        // the menu, even if (somehow) a row marked one uninstalled.
        $user = User::factory()->create();
        $principal = $this->principal($user);
        app(AppInstallService::class)->uninstall($principal, 'appearance');

        $this->actingAs($user)->postJson('/system-x/wm/launch', ['app' => 'appearance'])
            ->assertOk()
            ->assertJsonPath('app', 'appearance');
    }

    /** @return array<int, string> */
    private function openSlugsFor(StateKey $principal): array
    {
        return collect(app(OpenWindowService::class)->forPrincipal($principal))
            ->pluck('app')
            ->all();
    }
}

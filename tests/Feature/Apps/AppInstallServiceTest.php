<?php

namespace Tests\Feature\Apps;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Apps\Installs\AppInstallService;
use SystemX\Core\Apps\Installs\UninstalledApp;
use SystemX\Core\State\StateKey;
use Tests\TestCase;

class AppInstallServiceTest extends TestCase
{
    use RefreshDatabase;

    private function principal(User $user): StateKey
    {
        // The service keys on (principalType, principalId) -- the SAME shape as the open-set
        // (5a) and the bag key (4a/4c). The app-set ops use ('user', userId, ''); windowId is
        // irrelevant to the per-USER uninstalled set, so pass a placeholder.
        return new StateKey('user', (string) $user->id, '');
    }

    public function test_uninstall_marks_an_app_and_it_reads_back_as_uninstalled(): void
    {
        $user = User::factory()->create();
        $service = app(AppInstallService::class);

        $service->uninstall($this->principal($user), 'hello');

        $this->assertTrue($service->isUninstalled($this->principal($user), 'hello'));
        $this->assertSame(['hello'], $service->uninstalledFor($this->principal($user)));
    }

    public function test_install_deletes_the_row_so_the_app_is_no_longer_uninstalled(): void
    {
        $user = User::factory()->create();
        $service = app(AppInstallService::class);
        $service->uninstall($this->principal($user), 'hello');

        $service->install($this->principal($user), 'hello');

        $this->assertFalse($service->isUninstalled($this->principal($user), 'hello'));
        $this->assertSame([], $service->uninstalledFor($this->principal($user)));
    }

    public function test_a_fresh_principal_has_nothing_uninstalled(): void
    {
        // The SUBTRACTIVE default (D1): a brand-new user has ZERO rows -- nothing is
        // uninstalled, so everything shows. No first-boot seeding.
        $user = User::factory()->create();
        $service = app(AppInstallService::class);

        $this->assertFalse($service->isUninstalled($this->principal($user), 'hello'));
        $this->assertSame([], $service->uninstalledFor($this->principal($user)));
    }

    public function test_uninstall_is_idempotent_twice_is_one_row(): void
    {
        // firstOrCreate on (principal, app): uninstalling the same app twice marks it once,
        // no error, no duplicate row.
        $user = User::factory()->create();
        $service = app(AppInstallService::class);

        $service->uninstall($this->principal($user), 'hello');
        $service->uninstall($this->principal($user), 'hello');

        $this->assertSame(['hello'], $service->uninstalledFor($this->principal($user)));
        $this->assertSame(1, UninstalledApp::query()
            ->where('principal_type', 'user')
            ->where('principal_id', (string) $user->id)
            ->where('app', 'hello')
            ->count());
    }

    public function test_the_uninstalled_set_is_per_user(): void
    {
        // Per-USER: uninstalling for user A must NOT affect user B.
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $service = app(AppInstallService::class);

        $service->uninstall($this->principal($alice), 'hello');

        $this->assertTrue($service->isUninstalled($this->principal($alice), 'hello'));
        $this->assertFalse($service->isUninstalled($this->principal($bob), 'hello'));
        $this->assertSame([], $service->uninstalledFor($this->principal($bob)));
    }
}

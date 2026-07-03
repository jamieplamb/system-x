<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Apps\Installs\AppInstallService;
use SystemX\Core\Audit\AuditActivity;
use SystemX\Core\Audit\AuditChange;
use SystemX\Core\State\StateKey;
use Tests\TestCase;

// Task 6 (audit plan): one activity row per lifecycle action (launch, close, install, uninstall).
// No change rows for these -- lifecycle is ACTIVITY only, no $delta.
class LifecycleAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_launch_records_window_launch(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/system-x/wm/launch', ['app' => 'hello'])
            ->assertOk();

        $windowId = $response->json('window');

        $activity = AuditActivity::query()->sole();
        $this->assertSame('window.launch', $activity->event);
        $this->assertSame('hello', $activity->app);
        $this->assertSame('ok', $activity->outcome);
        $this->assertSame($windowId, $activity->window_id);
        $this->assertSame(0, AuditChange::query()->count());
    }

    public function test_close_records_window_close(): void
    {
        $user = User::factory()->create();
        $principal = new StateKey('user', (string) $user->id, '');

        // Launch first to get a window id (this also writes a window.launch row).
        $response = $this->actingAs($user)
            ->postJson('/system-x/wm/launch', ['app' => 'hello'])
            ->assertOk();

        $windowId = $response->json('window');

        $this->actingAs($user)
            ->postJson('/system-x/wm/close', ['window' => $windowId])
            ->assertNoContent();

        // Two activity rows total (launch + close) -- filter to the close one specifically.
        $activity = AuditActivity::query()->where('event', 'window.close')->sole();
        $this->assertSame('window.close', $activity->event);
        $this->assertSame('hello', $activity->app);
        $this->assertSame('ok', $activity->outcome);
        $this->assertSame($windowId, $activity->window_id);
        $this->assertSame(0, AuditChange::query()->count());
    }

    public function test_install_records_app_install(): void
    {
        $user = User::factory()->create();
        $principal = new StateKey('user', (string) $user->id, '');

        // Uninstall first so the install has something meaningful to do,
        // but the assertion is on the audit row regardless.
        app(AppInstallService::class)->uninstall($principal, 'notes');

        $this->actingAs($user)
            ->postJson('/system-x/app/install', ['app' => 'notes'])
            ->assertNoContent();

        $activity = AuditActivity::query()->sole();
        $this->assertSame('app.install', $activity->event);
        $this->assertSame('notes', $activity->app);
        $this->assertSame('ok', $activity->outcome);
        $this->assertNull($activity->window_id);
        $this->assertSame(0, AuditChange::query()->count());
    }

    public function test_uninstall_records_app_uninstall(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/system-x/app/uninstall', ['app' => 'notes'])
            ->assertNoContent();

        $activity = AuditActivity::query()->sole();
        $this->assertSame('app.uninstall', $activity->event);
        $this->assertSame('notes', $activity->app);
        $this->assertSame('ok', $activity->outcome);
        $this->assertNull($activity->window_id);
        $this->assertSame(0, AuditChange::query()->count());
    }
}

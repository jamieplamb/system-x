<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\Audit\AuditActivity;
use SystemX\Core\Audit\AuditChange;
use SystemX\Core\Runtime\App;
use SystemX\Core\Runtime\AppRegistry;
use SystemX\Core\State\StateKey;
use SystemX\Core\Widgets\Button;
use SystemX\Core\Widgets\Window;
use SystemX\Core\Wire\Node;
use SystemX\Core\Wm\OpenWindowService;
use Tests\TestCase;

class EventAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_event_records_activity_ok_and_change_sharing_correlation(): void
    {
        $user = User::factory()->create();
        $win = app(OpenWindowService::class)->launch(new StateKey('user', (string) $user->id, ''), 'hello');

        $this->actingAs($user)->postJson('/system-x/event', [
            'window' => $win->window_id, 'app' => 'hello', 'widget' => 'clicker', 'event' => 'click',
        ])->assertNoContent();

        $activity = AuditActivity::query()->sole();
        $this->assertSame('ok', $activity->outcome);
        $this->assertSame('hello', $activity->app);
        $this->assertSame('clicker', $activity->widget_id);

        $change = AuditChange::query()->sole();
        $this->assertSame($activity->correlation_id, $change->correlation_id);
        $this->assertSame('count', $change->property);
    }

    public function test_throwing_handler_rolls_back_state_but_records_error_activity(): void
    {
        app(AppRegistry::class)->register('boom', BoomApp::class);

        $user = User::factory()->create();
        $win = app(OpenWindowService::class)->launch(new StateKey('user', (string) $user->id, ''), 'boom');

        // Post-isolation (PH Task 5): a throwing handler is caught and returned as an {error}
        // envelope (200), NOT re-thrown to a 500. The rollback + error-activity row are the same.
        $this->actingAs($user)->postJson('/system-x/event', [
            'window' => $win->window_id, 'app' => 'boom', 'widget' => 'detonate', 'event' => 'click',
        ])->assertOk()->assertJsonPath('error.app', 'boom');

        $this->assertSame(0, AuditChange::query()->count());
        $activity = AuditActivity::query()->sole();
        $this->assertSame('error', $activity->outcome);
        $this->assertSame('boom', $activity->app);
    }
}

class BoomApp extends App
{
    public int $count = 0;

    public function slug(): string
    {
        return 'boom';
    }

    public function render(): Node
    {
        return Window::make('Boom')->content([
            Button::make('Detonate')->id('detonate')->handles('detonate'),
        ]);
    }

    public function detonate(): void
    {
        throw new \RuntimeException('boom');
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use SystemX\Core\Audit\AuditActivity;
use SystemX\Core\Events\DesktopRendered;
use SystemX\Core\Runtime\App;
use SystemX\Core\Runtime\AppRegistry;
use SystemX\Core\State\DatabaseStateStore;
use SystemX\Core\State\StateBag;
use SystemX\Core\State\StateKey;
use SystemX\Core\State\StateStore;
use SystemX\Core\Widgets\Button;
use SystemX\Core\Widgets\Window;
use SystemX\Core\Wire\Node;
use SystemX\Core\Wm\OpenWindowService;
use Tests\TestCase;

// PH Task 5 -- per-app handler error isolation. A single app's handler throwing must NOT
// 500 the whole desktop: the event endpoint returns {error} (200), the state save rolls
// back, an error-activity row survives, and NO DesktopRendered broadcast fires (the client
// keeps its last-good tree). The happy path (204 + broadcast) must be untouched.
class EventIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_throwing_handler_is_isolated_not_a_500(): void
    {
        Event::fake([DesktopRendered::class]);

        app(AppRegistry::class)->register('crasher', CrasherApp::class);

        $user = User::factory()->create();
        $win = app(OpenWindowService::class)->launch(new StateKey('user', (string) $user->id, ''), 'crasher');

        // Seed durable state so we can prove the mutation the handler attempts is rolled back.
        $stateKey = new StateKey('user', (string) $user->id, $win->window_id);
        $this->app->make(StateStore::class)->save(
            $stateKey,
            new StateBag(['count' => 7], DatabaseStateStore::SCHEMA_VERSION),
        );

        $response = $this->actingAs($user)->postJson('/system-x/event', [
            'window' => $win->window_id, 'app' => 'crasher', 'widget' => 'detonate', 'event' => 'click',
        ]);

        // Isolated: a 200 with an {error} envelope, NOT a 500 exception.
        $response->assertOk();
        $response->assertJsonPath('error.app', 'crasher');
        $this->assertNotEmpty($response->json('error.message'));

        // The generic client message NEVER leaks the exception detail (audit-only).
        $this->assertStringNotContainsString('kaboom', (string) $response->json('error.message'));

        // Durable state unchanged -- the txn rolled back the handler's mutation.
        $this->assertSame(7, $this->app->make(StateStore::class)->load($stateKey)->get('count'));

        // An error-activity row survives the rollback (recorded outside the closure).
        $activity = AuditActivity::query()->sole();
        $this->assertSame('error', $activity->outcome);
        $this->assertSame('crasher', $activity->app);

        // No broadcast -- the client keeps its last-good tree.
        Event::assertNotDispatched(DesktopRendered::class);
    }

    public function test_a_succeeding_handler_still_204s_and_broadcasts(): void
    {
        Event::fake([DesktopRendered::class]);

        $user = User::factory()->create();
        $win = app(OpenWindowService::class)->launch(new StateKey('user', (string) $user->id, ''), 'hello');

        $this->actingAs($user)->postJson('/system-x/event', [
            'window' => $win->window_id, 'app' => 'hello', 'widget' => 'clicker', 'event' => 'click',
        ])->assertNoContent();

        Event::assertDispatched(DesktopRendered::class);
    }
}

// A demo app whose handler mutates its state THEN throws -- proves both the isolation
// (no 500) and the rollback (the mutation never persists). Registered in-test only; the
// gallery crash button is a later PH task.
class CrasherApp extends App
{
    public int $count = 0;

    public function slug(): string
    {
        return 'crasher';
    }

    public function render(): Node
    {
        return Window::make('Crasher')->content([
            Button::make('Detonate')->id('detonate')->handles('detonate'),
        ]);
    }

    public function detonate(): void
    {
        // Mutate first, then blow up -- the txn must roll this back.
        $this->count = 999;

        throw new \RuntimeException('kaboom: internal detail that must never reach the client');
    }
}

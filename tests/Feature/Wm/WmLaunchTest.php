<?php

namespace Tests\Feature\Wm;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SystemX\Core\State\StateKey;
use SystemX\Core\State\WindowState;
use SystemX\Core\Wm\OpenWindow;
use SystemX\Core\Wm\OpenWindowService;
use Tests\TestCase;

// Task 8 (Plan 5a, D7): POST /system-x/wm/launch {app} mints a window (or returns the
// existing one for a singleton app, S4), writes the open-row, and returns the app's
// initial tree from an EMPTY bag (born stateless -- no store write). The client mints a
// surface and paints the tree into it.
class WmLaunchTest extends TestCase
{
    use RefreshDatabase;

    private function principal(User $user): StateKey
    {
        return new StateKey('user', (string) $user->id, '');
    }

    public function test_launch_hello_mints_a_window_and_returns_its_initial_tree(): void
    {
        $user = User::factory()->create();

        $res = $this->actingAs($user)->postJson('/system-x/wm/launch', ['app' => 'hello'])
            ->assertOk()
            ->assertJsonPath('app', 'hello')
            ->assertJsonPath('tree.children.0.props.text', 'Clicked 0 times');

        $window = $res->json('window');
        $this->assertSame(26, strlen($window)); // a ULID
        // The open-window row exists; NO bag row (born stateless -- first event creates it).
        $this->assertTrue(app(OpenWindowService::class)->isOpen($this->principal($user), $window));
        $this->assertSame(0, WindowState::query()->where('window_id', $window)->count());
    }

    // B3: the dynamic path's whole point is opening a SECOND KIND of window. notes' initial
    // tree shape DIFFERS from hello's (a TextField, not a count label), so renderFromBag's
    // dynamic path must be exercised against the REAL notes app -- not just hello. Assert a
    // notes-specific marker (the `message-field` widget id), NOT a count string.
    public function test_launch_notes_returns_its_own_distinct_initial_tree(): void
    {
        $user = User::factory()->create();

        $res = $this->actingAs($user)->postJson('/system-x/wm/launch', ['app' => 'notes'])
            ->assertOk()
            ->assertJsonPath('app', 'notes')
            // notes-specific: its initial tree carries the message-field widget, not a counter.
            ->assertJsonFragment(['id' => 'message-field']);

        $window = $res->json('window');
        $this->assertSame(26, strlen($window)); // a ULID, like hello
        $this->assertTrue(app(OpenWindowService::class)->isOpen($this->principal($user), $window));
        $this->assertSame(0, WindowState::query()->where('window_id', $window)->count());
    }

    public function test_launch_rejects_an_unknown_app(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/system-x/wm/launch', ['app' => 'ghost'])
            ->assertStatus(422);
    }

    public function test_a_second_launch_of_the_same_app_returns_the_same_window_no_second_row(): void
    {
        // S4 at the endpoint: launch is singleton-per-app server-side. A second POST returns
        // the SAME window id and does NOT mint a second open-row (bounded against a POST loop).
        $user = User::factory()->create();

        $first = $this->actingAs($user)->postJson('/system-x/wm/launch', ['app' => 'hello'])->json('window');
        $second = $this->actingAs($user)->postJson('/system-x/wm/launch', ['app' => 'hello'])->json('window');

        $this->assertSame($first, $second);
        $this->assertSame(1, OpenWindow::query()->where('principal_id', (string) $user->id)->where('app', 'hello')->count());
    }

    public function test_launch_requires_auth(): void
    {
        $this->postJson('/system-x/wm/launch', ['app' => 'hello'])->assertUnauthorized();
    }
}

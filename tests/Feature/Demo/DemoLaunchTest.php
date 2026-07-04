<?php

namespace Tests\Feature\Demo;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Tests\DemoModeTestCase;

class DemoLaunchTest extends DemoModeTestCase
{
    use RefreshDatabase;

    public function test_launch_mints_one_demo_user_logs_in_and_redirects(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $response = $this->post('/demo/launch');

        $response->assertRedirect('/');
        $this->assertSame(1, User::query()->where('is_demo', true)->count());

        $user = User::query()->where('is_demo', true)->first();
        $this->assertSame('Guest', $user->name);
        $this->assertStringEndsWith('@system-x.invalid', $user->email);
        $this->assertNotNull($user->last_active_at);
        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
    }

    public function test_launch_opens_the_welcome_window_plus_the_seeded_example_apps(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->post('/demo/launch');

        $user = User::query()->where('is_demo', true)->first();
        $principal = ['principal_type' => 'user', 'principal_id' => (string) $user->id];

        // A populated demo desktop: the welcome window plus the seeded hello/notes pair.
        // seedDefaults must run BEFORE the welcome window is opened (it no-ops once any window
        // exists), so all three being present proves the ordering in DemoController is right.
        $this->assertDatabaseHas('system_x_open_windows', $principal + ['app' => 'welcome']);
        $this->assertDatabaseHas('system_x_open_windows', $principal + ['app' => 'hello']);
        $this->assertDatabaseHas('system_x_open_windows', $principal + ['app' => 'notes']);
    }

    public function test_launch_renders_capacity_and_mints_nothing_at_cap(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        // Runtime config() is fine for max_users: the controller reads it at REQUEST time.
        config(['system-x-demo.max_users' => 2]);
        User::factory()->count(2)->create(['is_demo' => true]);

        $this->post('/demo/launch')
            ->assertOk()
            ->assertViewIs('demo.capacity');

        $this->assertSame(2, User::query()->where('is_demo', true)->count());
        $this->assertFalse(Auth::check());
    }

    public function test_launch_throttles_after_five_per_minute(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/demo/launch')->assertRedirect('/');
            Auth::logout();
        }

        $this->post('/demo/launch')->assertStatus(429);
    }
}

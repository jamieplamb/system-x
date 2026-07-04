<?php

namespace Tests\Feature\Demo;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TouchDemoActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['system-x-demo.enabled' => true]);
    }

    public function test_touches_last_active_when_stale(): void
    {
        $user = User::factory()->create([
            'is_demo' => true,
            'last_active_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($user)->get('/'); // any web+auth route

        $this->assertTrue($user->fresh()->last_active_at->gt(now()->subMinute()));
    }

    public function test_does_not_touch_when_fresh(): void
    {
        $fresh = now()->subSeconds(5);
        $user = User::factory()->create(['is_demo' => true, 'last_active_at' => $fresh]);

        $this->actingAs($user)->get('/');

        $this->assertSame(
            $fresh->timestamp,
            $user->fresh()->last_active_at->timestamp,
        );
    }

    public function test_no_touch_for_non_demo_user(): void
    {
        $user = User::factory()->create(['is_demo' => false, 'last_active_at' => null]);

        $this->actingAs($user)->get('/');

        $this->assertNull($user->fresh()->last_active_at);
    }

    public function test_no_touch_when_flag_off(): void
    {
        config(['system-x-demo.enabled' => false]);
        $user = User::factory()->create(['is_demo' => true, 'last_active_at' => null]);

        $this->actingAs($user)->get('/');

        $this->assertNull($user->fresh()->last_active_at);
    }
}

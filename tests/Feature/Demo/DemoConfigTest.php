<?php

namespace Tests\Feature\Demo;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DemoConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_mode_defaults_off(): void
    {
        $this->assertFalse(config('system-x-demo.enabled'));
        $this->assertSame(500, config('system-x-demo.max_users'));
        $this->assertSame(30, config('system-x-demo.idle_minutes'));
    }

    public function test_user_carries_demo_columns(): void
    {
        $user = User::query()->create([
            'name' => 'Guest',
            'email' => 'demo+x@system-x.invalid',
            'password' => 'secret',
            'is_demo' => true,
            'last_active_at' => now(),
        ]);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->is_demo);
        $this->assertNotNull($fresh->last_active_at);
        $this->assertInstanceOf(Carbon::class, $fresh->last_active_at);
    }
}

<?php

namespace Tests\Feature\Demo;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoLaunchOffTest extends TestCase
{
    use RefreshDatabase;

    public function test_launch_404s_when_demo_off(): void
    {
        // Plain TestCase boots with the env unset -> the route was never registered.
        $this->post('/demo/launch')->assertNotFound();
        $this->assertSame(0, User::query()->count());
    }
}

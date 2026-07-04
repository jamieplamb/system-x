<?php

namespace Tests\Feature\Demo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoLoginSwapTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_shows_greeter_when_demo_off(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertViewIs('system-x::greeter');
    }

    public function test_login_shows_landing_when_demo_on(): void
    {
        config(['system-x-demo.enabled' => true]);

        $this->get('/login')
            ->assertOk()
            ->assertViewIs('demo.landing')
            ->assertSee('Launch', false); // the button label (placeholder)
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example. The desktop shell is auth-gated now (Task 3), so a
     * guest hitting `/` is redirected to /login rather than served a 200.
     */
    public function test_the_application_redirects_a_guest_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_demo_user_with_a_verifiable_password(): void
    {
        $this->seed(DemoUserSeeder::class);

        $user = User::query()->where('email', 'demo@system-x.test')->first();

        $this->assertNotNull($user);
        // The password is hashed (the model's 'hashed' cast) and the known dev
        // credential verifies -- this is what the login + Dusk flows authenticate with.
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(DemoUserSeeder::class);
        $this->seed(DemoUserSeeder::class); // second run must not duplicate

        $this->assertSame(1, User::query()->where('email', 'demo@system-x.test')->count());
    }

    public function test_it_seeds_in_the_testing_environment(): void
    {
        // The seeder is GUARDED to local/testing only (it must NEVER create the known
        // dev credential in prod). The host suite runs in `testing`, so it DOES seed
        // here -- this asserts the env guard lets the fixture through where it should.
        $this->assertSame('testing', $this->app->environment());

        $this->seed(DemoUserSeeder::class);

        $this->assertNotNull(User::query()->where('email', 'demo@system-x.test')->first());
    }
}

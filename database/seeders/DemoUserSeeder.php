<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

// The single dev/Dusk login fixture (D2). There is NO public registration in 4c;
// login authenticates an existing user, and this is the user that always exists.
// firstOrCreate keeps it idempotent so re-seeding never duplicates. The password is
// a DEV credential -- a real deployment seeds its own users; this never goes near a
// committed .env. The model's 'hashed' cast hashes the plain value on assignment.
//
// ENV-GUARDED: the known dev credential is created ONLY in local/testing. In any
// other environment (prod, staging) this seeder is a NO-OP, so `php artisan db:seed`
// in prod can never plant a brute-force target. The throttle (D8) defends the dev
// surface; this guard removes the prod surface entirely.
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        User::query()->firstOrCreate(
            ['email' => 'demo@system-x.test'],
            [
                'name' => 'Demo User',
                'password' => 'password',
            ],
        );
    }
}

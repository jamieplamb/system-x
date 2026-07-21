<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use SystemX\ProDataGrid\Demo\database\seeders\DemoGridSeeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(DemoUserSeeder::class);

        // The pro-datagrid demo data (Cameras grid). Guarded to dev/test/CI so it seeds the dev/Dusk
        // DB but never a real environment -- the demo app + its tables only exist there anyway (the
        // package provider's env guard), so seeding them elsewhere would just error on missing tables.
        // `ci` matches the provider guard; the Dusk test also self-seeds so it doesn't depend on CI
        // running db:seed (CI only runs `migrate`).
        if (app()->environment('local', 'testing', 'ci')) {
            $this->call(DemoGridSeeder::class);
        }
    }
}

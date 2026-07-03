<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use SystemX\Core\State\WindowState;
use Tests\TestCase;

class WindowStateTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_package_migration_creates_the_window_states_table(): void
    {
        // Proves loadMigrationsFrom wired the package migration into the host
        // suite -- without it the table is silently absent under sqlite :memory:.
        $this->assertTrue(
            Schema::hasTable('system_x_window_states'),
            'system_x_window_states is missing -- loadMigrationsFrom is not wired or the migration did not run.',
        );

        $this->assertTrue(Schema::hasColumns('system_x_window_states', [
            'id',
            'principal_type',
            'principal_id',
            'window_id',
            'bag',
            'schema_version',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_the_bag_json_cast_round_trips_an_array(): void
    {
        $bag = ['x' => 10, 'open' => true, 'nested' => ['a', 'b']];

        $state = WindowState::create([
            'principal_type' => 'desktop',
            'principal_id' => 'b6c8e0f0-0000-4000-8000-000000000000',
            'window_id' => 'win-1',
            'bag' => $bag,
            'schema_version' => 1,
        ]);

        $fresh = WindowState::findOrFail($state->id);

        $this->assertSame($bag, $fresh->bag);
        $this->assertSame(1, $fresh->schema_version);
    }
}

<?php

namespace Tests\Feature\Demo;

use App\Models\User;
use App\Support\DemoUserPruner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneDemoUsersTest extends TestCase
{
    use RefreshDatabase;

    /** Seed one row keyed on the given user in every per-user table. */
    private function seedStateFor(User $user): void
    {
        foreach (DemoUserPruner::PER_USER_TABLES as $table) {
            DB::table($table)->insert($this->rowFor($table, (string) $user->id));
        }
    }

    /**
     * Minimal valid row per table. principal_type/principal_id are the load-bearing keys;
     * everything else is the table's NOT NULL columns (verified against each create migration).
     * Kept in the test so the assertion is self-contained.
     */
    private function rowFor(string $table, string $principalId): array
    {
        $base = ['principal_type' => 'user', 'principal_id' => $principalId];

        return $base + $this->extraColumnsFor($table);
    }

    /** The non-key NOT NULL columns per table, straight from the create migrations. */
    private function extraColumnsFor(string $table): array
    {
        return match ($table) {
            'system_x_window_states' => [
                'window_id' => 'w-1',
                'bag' => '{}',
                'schema_version' => 1,
            ],
            'system_x_open_windows' => [
                'window_id' => '01JZ0000000000000000000000',
                'app' => 'welcome',
            ],
            'system_x_preferences' => [
                'prefs' => '{}',
            ],
            'system_x_uninstalled_apps' => [
                'app' => 'welcome',
            ],
            'system_x_launcher_layout' => [
                'layout' => '[]',
            ],
            'system_x_audit_activity' => [
                'correlation_id' => '01JZ0000000000000000000000',
                'app' => 'welcome',
                'event' => 'launch',
                'outcome' => 'ok',
            ],
            'system_x_audit_changes' => [
                'correlation_id' => '01JZ0000000000000000000000',
                'app' => 'welcome',
                'property' => 'geometry',
            ],
            default => [],
        };
    }

    public function test_prunes_idle_demo_user_and_all_state(): void
    {
        $idleMinutes = (int) config('system-x-demo.idle_minutes');

        $idle = User::factory()->create(['is_demo' => true, 'last_active_at' => now()->subMinutes($idleMinutes + 5)]);
        $fresh = User::factory()->create(['is_demo' => true, 'last_active_at' => now()]);
        $real = User::factory()->create(['is_demo' => false, 'last_active_at' => now()->subDays(30)]);

        $this->seedStateFor($idle);
        $this->seedStateFor($fresh);
        $this->seedStateFor($real);

        app(DemoUserPruner::class)->prune();

        // The idle demo user and ALL its state are gone.
        $this->assertDatabaseMissing('users', ['id' => $idle->id]);
        foreach (DemoUserPruner::PER_USER_TABLES as $table) {
            $this->assertDatabaseMissing($table, ['principal_id' => (string) $idle->id]);
        }

        // The fresh demo user and the real user (and their state) are untouched.
        $this->assertDatabaseHas('users', ['id' => $fresh->id]);
        $this->assertDatabaseHas('users', ['id' => $real->id]);
        foreach (DemoUserPruner::PER_USER_TABLES as $table) {
            $this->assertDatabaseHas($table, ['principal_id' => (string) $fresh->id]);
            $this->assertDatabaseHas($table, ['principal_id' => (string) $real->id]);
        }
    }

    public function test_command_runs_the_pruner(): void
    {
        $idleMinutes = (int) config('system-x-demo.idle_minutes');
        $idle = User::factory()->create(['is_demo' => true, 'last_active_at' => now()->subMinutes($idleMinutes + 5)]);

        $this->artisan('system-x:prune-demo-users')->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $idle->id]);
    }
}

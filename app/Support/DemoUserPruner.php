<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

// Live-demo GC (showcase plan). Deletes is_demo users idle past the threshold and ALL their
// durable per-user state. The per-user tables key on principal_id = (string) user id (plain
// string columns, NO cascading FK), so each is deleted explicitly. PER_USER_TABLES is the single
// canonical list -- both this pruner and its completeness test consume it, so they can't drift.
class DemoUserPruner
{
    // THE ONE PLACE to update when a new table keys on principal_id. Adding a per-user table
    // WITHOUT adding it here leaves demo-user rows behind. The completeness test asserts every
    // table in this list is emptied for a pruned user; it cannot detect a table missing from the
    // list, so this comment is the safety net, not the test.
    public const PER_USER_TABLES = [
        'system_x_window_states',
        'system_x_open_windows',
        'system_x_preferences',
        'system_x_uninstalled_apps',
        'system_x_launcher_layout',
        'system_x_audit_activity',
        'system_x_audit_changes',
    ];

    /** @return int number of demo users pruned */
    public function prune(): int
    {
        $idleMinutes = (int) config('system-x-demo.idle_minutes');
        $cutoff = now()->subMinutes($idleMinutes);

        // is_demo is the safety rail: a real user (is_demo false/null) is never selected.
        $ids = User::query()
            ->where('is_demo', true)
            ->where('last_active_at', '<', $cutoff)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($ids === []) {
            return 0;
        }

        foreach (self::PER_USER_TABLES as $table) {
            DB::table($table)
                ->where('principal_type', 'user')
                ->whereIn('principal_id', $ids)
                ->delete();
        }

        return User::query()->whereIn('id', $ids)->delete();
    }
}

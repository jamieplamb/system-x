<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The desktop-BOOTSTRAP marker (seed-once-ever fix). A nullable timestamp on the existing
// per-user prefs row: stamped the first time we seed the default windows, then never again.
// This is distinct from the cosmetic look prefs (theme/accent/...) -- it rides the SAME
// per-user row but is a real column, NOT part of the validated JSON bag.
//
// Nullable on purpose: existing rows get NULL = not-yet-marked, handled gracefully by the
// route (a user with windows is cleanly marked on their next boot with no spurious re-seed;
// a user sitting on an empty desktop gets ONE final re-seed then it's fixed forever). No
// cross-table backfill here -- keep it a simple add-column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_x_preferences', function (Blueprint $table): void {
            $table->timestamp('desktop_seeded_at')->nullable()->after('prefs');
        });
    }

    public function down(): void
    {
        Schema::table('system_x_preferences', function (Blueprint $table): void {
            $table->dropColumn('desktop_seeded_at');
        });
    }
};

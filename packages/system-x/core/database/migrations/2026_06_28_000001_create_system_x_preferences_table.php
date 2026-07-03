<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The per-user PREFERENCES store (Plan 5b-2, D1). Keyed by the 2-TUPLE
// (principal_type, principal_id) ONLY -- per-user, NOT per-window (there is NO
// window_id, unlike system_x_window_states + system_x_open_windows). The prefs are a
// JSON bag (theme/accent/wallpaper/panel_position) so a new pref key needs no migration
// (mirrors the state bag's casted JSON). Timestamp AFTER the open-windows migration
// (_000000) so the order is deterministic (N1).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_x_preferences', function (Blueprint $table): void {
            $table->id();
            $table->string('principal_type');
            $table->string('principal_id');
            $table->json('prefs');       // theme/accent/wallpaper/panel_position (the bag, D1)
            $table->timestamps();

            // The PER-USER key: one row per (principal_type, principal_id) -- NO window_id.
            // Short explicit name -- the auto-generated one blows past MySQL's 64-char limit.
            $table->unique(['principal_type', 'principal_id'], 'sx_preferences_principal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_x_preferences');
    }
};

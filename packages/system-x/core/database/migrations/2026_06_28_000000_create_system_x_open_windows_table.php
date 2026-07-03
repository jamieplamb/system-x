<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The per-user OPEN-WINDOW SET (Plan 5a, D7). SAME key shape as
// system_x_window_states (principal_type + principal_id + window_id, all strings) so a
// ULID window id OR a static slug both fit. This is the ONLY new table -- the state bag
// table is untouched (the ULID absorbs into its existing varchar window_id).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_x_open_windows', function (Blueprint $table): void {
            $table->id();
            $table->string('principal_type');
            $table->string('principal_id');
            $table->string('window_id'); // a ULID, or a slug for the static pair
            $table->string('app');       // the registered app slug to render into this window
            $table->timestamps();

            // The bag key shape: one row per (user, window). isOpen / appFor / close all
            // address a window by this triple. The index name is given explicitly -- the
            // auto-generated one blows past MySQL's 64-char identifier limit.
            $table->unique(['principal_type', 'principal_id', 'window_id'], 'sx_open_windows_principal_window_unique');

            // The singleton-per-app guard (S4/S1): launch()'s firstOrCreate keys on
            // (principal, app), but read-then-insert is NOT atomic -- two concurrent
            // launches of the same app could both miss and both INSERT a fresh ULID. A
            // UNIQUE index makes the losing INSERT throw, which firstOrCreate reads through,
            // so one window per (user, app) is enforced atomically at the DB (and hardens
            // seedDefaults against a concurrent double-first-boot, N1). Short explicit name
            // -- the auto-generated one blows past MySQL's 64-char identifier limit.
            // NOTE: this unique constraint enforces singleton-per-app for 5a. It MUST be
            // RELAXED in Plan 5b when intentional multi-instance windows land.
            $table->unique(['principal_type', 'principal_id', 'app'], 'sx_open_windows_principal_app_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_x_open_windows');
    }
};

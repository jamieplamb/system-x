<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The per-user SUBTRACTIVE uninstalled-app SET (App-install plan, D1). One row =
// "this user uninstalled this app". A fresh user has ZERO rows (everything shows) --
// NO first-boot seeding, new registered apps auto-appear. Same key shape as
// system_x_open_windows (principal_type + principal_id, both strings) so a uuid OR an
// int user id both fit. The third column is the registered app slug.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_x_uninstalled_apps', function (Blueprint $table): void {
            $table->id();
            $table->string('principal_type');
            $table->string('principal_id');
            $table->string('app'); // the registered app slug this user uninstalled
            $table->timestamps();

            // One row per (user, app). uninstall()'s firstOrCreate keys on this triple --
            // the UNIQUE index keeps it idempotent (a duplicate INSERT throws, firstOrCreate
            // reads through). Short explicit name -- the auto-generated one blows past
            // MySQL's 64-char identifier limit.
            $table->unique(['principal_type', 'principal_id', 'app'], 'sx_uninstalled_apps_principal_app_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_x_uninstalled_apps');
    }
};

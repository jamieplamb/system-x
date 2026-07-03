<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Plan 5e, D1: geometry EXTENDS the per-user open-windows row (it's 1:1 with the open-set,
// same lifecycle -- born on launch with NULL geometry, deleted on close). Plain nullable
// additions; the existing unique indexes are untouched. x/y/w/h/z hold the RESTORE rect +
// stacking (NULL until the client settles a window); sized/maximised/minimised are flags
// (default false, never NULL). saveGeometry is UPDATE-only -- the INSERT path is launch.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_x_open_windows', function (Blueprint $table): void {
            $table->integer('x')->nullable()->after('app');
            $table->integer('y')->nullable()->after('x');
            $table->integer('w')->nullable()->after('y');
            $table->integer('h')->nullable()->after('w');
            $table->boolean('sized')->default(false)->after('h');
            $table->boolean('maximised')->default(false)->after('sized');
            $table->boolean('minimised')->default(false)->after('maximised');
            $table->integer('z')->nullable()->after('minimised');
        });
    }

    public function down(): void
    {
        Schema::table('system_x_open_windows', function (Blueprint $table): void {
            $table->dropColumn(['x', 'y', 'w', 'h', 'sized', 'maximised', 'minimised', 'z']);
        });
    }
};

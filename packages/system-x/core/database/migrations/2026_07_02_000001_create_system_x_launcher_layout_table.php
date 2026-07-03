<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The per-user LAUNCHER LAYOUT (Plan 4a). Keyed by the 2-tuple (principal_type, principal_id)
// ONLY -- per-user, NOT per-window, mirroring system_x_preferences. `layout` is an ordered JSON
// document ([{type:app,slug} | {type:folder,id,name,apps:[]}, ...]) so folder arrangement needs
// no relational schema. Reconciled against the live app-set at render time.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_x_launcher_layout', function (Blueprint $table): void {
            $table->id();
            $table->string('principal_type');
            $table->string('principal_id');
            $table->json('layout');
            $table->timestamps();

            $table->unique(['principal_type', 'principal_id'], 'sx_launcher_layout_principal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_x_launcher_layout');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_x_window_states', function (Blueprint $table): void {
            // Surrogate PK -- Eloquent has no composite-PK support, so the key is a
            // UNIQUE index, not the primary key.
            $table->id();

            // The (principal_type, principal_id, window_id) key. principal_id is a
            // STRING so the int user id fits as-is -- the column stays type-agnostic
            // (a uuid would slot in with zero migration too).
            $table->string('principal_type', 32);  // 'user'
            $table->string('principal_id');         // the (string) user id
            $table->string('window_id', 64);        // the wire-sourced window slug

            // The state bag. JSON, no literal default (MySQL forbids it); the store
            // seeds an empty bag at the app layer on a miss.
            $table->json('bag');

            // The bag-FORMAT version stamp (D4) -- migrate-or-discard on mismatch.
            // Distinct from any future optimistic-lock version.
            $table->unsignedInteger('schema_version');

            $table->timestamps();

            // Two access patterns, two indexes:
            // 1. the load/save lookup + updateOrCreate target + create-race guard
            $table->unique(['principal_type', 'principal_id', 'window_id'], 'window_state_key');
            // 2. the GC TTL sweep (D5). The prune is a GLOBAL `WHERE updated_at < ?`
            //    with NO principal predicate, so the index must LEAD with updated_at
            //    (and here is updated_at ALONE). A composite leading with the
            //    principal columns would be unusable for this query under MySQL's
            //    leftmost-prefix rule and the sweep would full-scan. A per-principal
            //    sweep, if 4b/4c ever needs one, gets its OWN composite index then.
            $table->index('updated_at', 'window_state_gc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_x_window_states');
    }
};

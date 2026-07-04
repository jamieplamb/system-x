<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Showcase plan: mark ephemeral demo users and track their liveness. Both columns are
// nullable and unused in a non-demo deployment. is_demo is the prune safety rail (a real
// user has is_demo=false/null and can never be swept); last_active_at measures idle time.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false)->index();
            $table->timestamp('last_active_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_demo', 'last_active_at']);
        });
    }
};

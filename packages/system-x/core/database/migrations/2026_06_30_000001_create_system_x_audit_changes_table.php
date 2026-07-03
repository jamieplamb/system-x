<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_x_audit_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('correlation_id', 26);
            $table->string('principal_type');
            $table->string('principal_id');
            $table->string('app');
            $table->string('window_id')->nullable();
            $table->string('property');
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('correlation_id', 'sx_audit_changes_correlation');
            $table->index(['principal_type', 'principal_id', 'created_at'], 'sx_audit_changes_viewer');
            $table->index('created_at', 'sx_audit_changes_gc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_x_audit_changes');
    }
};

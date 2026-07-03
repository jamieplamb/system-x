<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_x_audit_activity', function (Blueprint $table): void {
            $table->id();
            $table->string('correlation_id', 26);
            $table->string('principal_type');
            $table->string('principal_id');
            $table->string('app');
            $table->string('window_id')->nullable();
            $table->string('widget_id')->nullable();
            $table->string('event');
            $table->string('outcome', 16);
            $table->json('value')->nullable();
            $table->json('payload')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['principal_type', 'principal_id', 'created_at'], 'sx_audit_activity_viewer');
            $table->index('correlation_id', 'sx_audit_activity_correlation');
            $table->index('created_at', 'sx_audit_activity_gc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_x_audit_activity');
    }
};

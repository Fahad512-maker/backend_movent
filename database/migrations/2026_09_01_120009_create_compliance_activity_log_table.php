<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Stub table for Phase 2+ — append-only audit trail for every
    // compliance state change. Mirrors system_audit_logs' old/new value
    // shape but adds first-class project_id/client_id columns since
    // Reports/Dashboard filter/group on those directly.
    public function up(): void
    {
        if (Schema::hasTable('compliance_activity_log')) return;

        Schema::create('compliance_activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('compliance_case_id')->nullable();
            // Both null = an automated/system event (e.g. auto-marked compliant).
            $table->unsignedBigInteger('actor_admin_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action', 60);
            $table->string('entity_type', 60)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
            $table->foreign('compliance_case_id')->references('id')->on('compliance_cases')->nullOnDelete();
            $table->foreign('actor_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['company_id', 'created_at']);
            $table->index(['project_id']);
            $table->index(['client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_activity_log');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('compliance_cases')) return;

        Schema::create('compliance_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // UNIQUE — the idempotency backstop ComplianceCaseService relies
            // on so a retried/racing project-creation request can never
            // produce two cases for the same project.
            $table->foreignId('project_id')->unique()->constrained('projects')->cascadeOnDelete();
            // Denormalized from project.client_id so a client-level
            // compliance rollup (Phase 3) never needs to join through projects.
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->enum('status', ['not_started', 'pending', 'under_review', 'compliant', 'on_hold', 'rejected'])
                ->default('not_started');
            $table->foreignId('template_id')->nullable()->constrained('compliance_templates')->nullOnDelete();
            $table->unsignedBigInteger('compliance_officer_id')->nullable();
            $table->timestamp('officer_assigned_at')->nullable();
            $table->unsignedBigInteger('officer_assigned_by_admin_id')->nullable();
            $table->unsignedBigInteger('officer_assigned_by_user_id')->nullable();
            $table->timestamp('started_review_at')->nullable();
            $table->timestamp('compliant_at')->nullable();
            $table->timestamp('on_hold_at')->nullable();
            $table->text('on_hold_reason')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamps();

            $table->foreign('compliance_officer_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('officer_assigned_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            $table->foreign('officer_assigned_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['company_id', 'status']);
            $table->index(['client_id']);
            $table->index(['compliance_officer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_cases');
    }
};

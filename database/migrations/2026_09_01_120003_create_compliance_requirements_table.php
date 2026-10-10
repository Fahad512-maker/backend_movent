<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('compliance_requirements')) return;

        Schema::create('compliance_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('compliance_case_id')->constrained('compliance_cases')->cascadeOnDelete();
            // Provenance only — never re-synced from the template after
            // instantiation, so editing a project's requirement never
            // mutates the template it came from.
            $table->foreignId('template_requirement_id')->nullable()
                ->constrained('compliance_template_requirements')->nullOnDelete();
            $table->enum('source', ['template', 'custom']);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('category', 60)->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->enum('status', [
                'pending', 'submitted', 'under_review', 'approved',
                'rejected', 'resubmission_requested', 'expired', 'waived',
            ])->default('pending');
            $table->boolean('requires_expiry')->default(false);
            $table->date('expires_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('added_by_admin_id')->nullable();
            $table->unsignedBigInteger('added_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('added_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            $table->foreign('added_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['compliance_case_id', 'status']);
            $table->index(['project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_requirements');
    }
};

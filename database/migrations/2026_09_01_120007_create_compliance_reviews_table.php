<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Stub table for Phase 2 — the review-action log (Mark Under Review/
    // Approve/Reject/Request Resubmission/Add Comment/Set Expiry). A null
    // compliance_requirement_id means a case-level action.
    public function up(): void
    {
        if (Schema::hasTable('compliance_reviews')) return;

        Schema::create('compliance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('compliance_case_id')->constrained('compliance_cases')->cascadeOnDelete();
            $table->foreignId('compliance_requirement_id')->nullable()
                ->constrained('compliance_requirements')->nullOnDelete();
            $table->foreignId('compliance_document_id')->nullable()
                ->constrained('compliance_documents')->nullOnDelete();
            $table->enum('action', [
                'mark_under_review', 'approve', 'reject', 'request_resubmission', 'add_comment', 'set_expiry',
            ]);
            // Mandatory at the app layer for reject/request_resubmission.
            $table->text('reason')->nullable();
            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30)->nullable();
            $table->unsignedBigInteger('performed_by_admin_id')->nullable();
            $table->unsignedBigInteger('performed_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('performed_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            $table->foreign('performed_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['compliance_case_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_reviews');
    }
};

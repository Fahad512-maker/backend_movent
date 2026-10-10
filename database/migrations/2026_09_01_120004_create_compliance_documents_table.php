<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Stub table for Phase 2 (document upload/review/versioning) — created
    // now so compliance_requirements/compliance_cases FKs and the
    // Phase 1 model relations don't need a reshaping migration later.
    public function up(): void
    {
        if (Schema::hasTable('compliance_documents')) return;

        Schema::create('compliance_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('compliance_case_id')->constrained('compliance_cases')->cascadeOnDelete();
            $table->foreignId('compliance_requirement_id')->nullable()
                ->constrained('compliance_requirements')->nullOnDelete();
            // Reuse an existing project attachment instead of duplicating the
            // file on disk, when the document was already uploaded there.
            $table->unsignedBigInteger('linked_attachment_id')->nullable();
            $table->unsignedBigInteger('uploaded_by_admin_id')->nullable();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->string('file_path', 600)->nullable();
            $table->string('file_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->enum('status', ['pending_review', 'approved', 'rejected', 'resubmission_requested', 'expired'])
                ->default('pending_review');
            $table->date('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('linked_attachment_id')->references('id')->on('project_attachments')->nullOnDelete();
            $table->foreign('uploaded_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            $table->foreign('uploaded_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['compliance_case_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_documents');
    }
};

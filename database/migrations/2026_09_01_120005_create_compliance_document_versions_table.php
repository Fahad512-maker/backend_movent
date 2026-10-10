<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Stub table for Phase 2 — never overwrite compliance history: a
    // rejected/resubmitted document keeps its old versions here, with
    // is_current marking the currently-active one.
    public function up(): void
    {
        if (Schema::hasTable('compliance_document_versions')) return;

        Schema::create('compliance_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('compliance_document_id')->constrained('compliance_documents')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('file_name', 255)->nullable();
            $table->string('file_path', 600)->nullable();
            $table->string('file_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedBigInteger('uploaded_by_admin_id')->nullable();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->boolean('is_current')->default(true);
            $table->enum('status', ['pending_review', 'approved', 'rejected', 'resubmission_requested', 'expired'])
                ->default('pending_review');
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->foreign('uploaded_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            $table->foreign('uploaded_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['compliance_document_id', 'is_current'], 'compliance_doc_versions_doc_current_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_document_versions');
    }
};

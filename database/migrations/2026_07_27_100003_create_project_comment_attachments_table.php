<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors project_attachments/project_task_attachments exactly — this
// codebase's established one-table-per-parent convention, scoped by
// comment_id instead of project_id/task_id.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_comment_attachments')) return;

        Schema::create('project_comment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('comment_id')->constrained('project_comments')->cascadeOnDelete();
            $table->unsignedBigInteger('uploaded_by_admin_id')->nullable();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->string('original_name', 255);
            $table->string('file_name', 255);
            $table->string('file_path', 600);
            $table->string('file_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();

            $table->foreign('uploaded_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            $table->foreign('uploaded_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['comment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_comment_attachments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            // Uploader can be a CompanyAdmin (no `users` row) or a staff User —
            // same dual-FK pattern already used by project_comments.
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
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_attachments');
    }
};

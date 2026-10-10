<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Some deployments' database was seeded from a raw SQL snapshot that
        // already included this table before migrations tracking began —
        // skip rather than error if it's already there.
        if (Schema::hasTable('project_folder_files')) return;

        Schema::create('project_folder_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->constrained('project_folders')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('file_name', 255);
            $table->string('file_path', 600);
            $table->string('disk', 100)->nullable()->default('local');
            $table->string('file_type', 60)->nullable();
            $table->string('file_extension', 20)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->unsignedTinyInteger('version')->default(1);
            $table->unsignedBigInteger('previous_version_id')->nullable();
            $table->tinyInteger('is_visible_to_client')->default(0);
            $table->string('description', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('previous_version_id')->references('id')->on('project_folder_files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_folder_files');
    }
};

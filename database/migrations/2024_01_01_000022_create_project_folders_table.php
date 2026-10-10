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
        if (Schema::hasTable('project_folders')) return;

        Schema::create('project_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedBigInteger('parent_folder_id')->nullable();
            $table->string('name', 200);
            $table->string('folder_path', 600);
            $table->tinyInteger('is_system')->default(0);
            $table->tinyInteger('is_visible_to_client')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->tinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('parent_folder_id')->references('id')->on('project_folders')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_folders');
    }
};

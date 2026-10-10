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
        if (Schema::hasTable('deliverables')) return;

        Schema::create('deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('title', 255);
            $table->string('file_path', 600)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->string('file_type', 50)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->enum('status', ['draft', 'delivered', 'approved', 'revision_requested'])->default('draft');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliverables');
    }
};

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
        if (Schema::hasTable('production_queue')) return;

        Schema::create('production_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->unique()->constrained('tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->enum('status', ['queued', 'in_progress', 'submitted', 'approved', 'rejected'])->default('queued');
            $table->integer('priority_order')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_queue');
    }
};

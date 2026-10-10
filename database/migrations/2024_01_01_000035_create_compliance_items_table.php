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
        if (Schema::hasTable('compliance_items')) return;

        Schema::create('compliance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('policy_id')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('category', 80)->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'overdue', 'waived'])->default('pending');
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('evidence_path', 600)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('policy_id')->references('id')->on('compliance_policies')->nullOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_items');
    }
};

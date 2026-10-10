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
        if (Schema::hasTable('compliance_incidents')) return;

        Schema::create('compliance_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->enum('category', ['data_breach', 'policy_violation', 'fraud', 'safety', 'other'])->nullable();
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->enum('status', ['open', 'investigating', 'resolved', 'closed'])->default('open');
            $table->timestamp('occurred_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->string('evidence_path', 600)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();

            $table->index(['company_id', 'severity', 'status']);
            $table->index(['project_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_incidents');
    }
};

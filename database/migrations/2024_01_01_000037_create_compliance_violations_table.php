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
        if (Schema::hasTable('compliance_violations')) return;

        Schema::create('compliance_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('policy_id')->nullable();
            $table->unsignedBigInteger('incident_id')->nullable();
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('violator_user_id')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->enum('severity', ['minor', 'moderate', 'major', 'critical'])->default('minor');
            $table->enum('status', ['open', 'under_review', 'resolved', 'escalated'])->default('open');
            $table->text('action_taken')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('policy_id')->references('id')->on('compliance_policies')->nullOnDelete();
            $table->foreign('incident_id')->references('id')->on('compliance_incidents')->nullOnDelete();
            $table->foreign('violator_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['company_id', 'status']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_violations');
    }
};

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
        if (Schema::hasTable('risk_assessments')) return;

        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('title', 255);
            $table->enum('category', ['operational', 'financial', 'legal', 'technical', 'reputational'])->nullable();
            $table->text('description')->nullable();
            $table->enum('likelihood', ['rare', 'unlikely', 'possible', 'likely', 'almost_certain'])->nullable();
            $table->enum('impact', ['negligible', 'minor', 'moderate', 'major', 'critical'])->nullable();
            $table->unsignedTinyInteger('risk_score')->nullable();
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])->nullable();
            $table->text('mitigation_plan')->nullable();
            $table->enum('status', ['identified', 'in_mitigation', 'resolved', 'accepted'])->default('identified');
            $table->date('review_date')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();

            $table->index(['company_id', 'risk_level']);
            $table->index(['project_id', 'risk_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};

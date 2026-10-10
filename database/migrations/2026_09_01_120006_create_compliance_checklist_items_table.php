<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Stub table for Phase 2 — "what the officer verifies", distinct from
    // compliance_requirements ("what the client provides"); items may be
    // auto-generated from a requirement (compliance_requirement_id set) or
    // added standalone (left null).
    public function up(): void
    {
        if (Schema::hasTable('compliance_checklist_items')) return;

        Schema::create('compliance_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('compliance_case_id')->constrained('compliance_cases')->cascadeOnDelete();
            $table->foreignId('compliance_requirement_id')->nullable()
                ->constrained('compliance_requirements')->nullOnDelete();
            $table->string('label', 255);
            $table->text('description')->nullable();
            $table->boolean('is_checked')->default(false);
            $table->unsignedBigInteger('checked_by_admin_id')->nullable();
            $table->unsignedBigInteger('checked_by_user_id')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('checked_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            $table->foreign('checked_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['compliance_case_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_checklist_items');
    }
};

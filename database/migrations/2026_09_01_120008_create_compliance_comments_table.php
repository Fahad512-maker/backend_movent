<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Stub table for Phase 2 — internal Compliance Officer notes. No
    // is_internal/visible_to_client flag: this table is inherently
    // internal-only, there is no client-facing read path for it.
    public function up(): void
    {
        if (Schema::hasTable('compliance_comments')) return;

        Schema::create('compliance_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('compliance_case_id')->constrained('compliance_cases')->cascadeOnDelete();
            $table->text('body');
            $table->unsignedBigInteger('author_admin_id')->nullable();
            $table->unsignedBigInteger('author_user_id')->nullable();
            $table->timestamps();

            $table->foreign('author_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            $table->foreign('author_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['compliance_case_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_comments');
    }
};

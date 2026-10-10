<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Dedicated Seller-assignment history for a project — narrower and more
// specific than the generic SystemAuditLog entry written alongside every
// row here (mirrors project_activities' relationship to SystemAuditLog).
// See App\Services\ProjectSellerAssignmentService.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_seller_assignments')) {
            return;
        }

        Schema::create('project_seller_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedBigInteger('old_seller_id')->nullable();
            $table->foreign('old_seller_id')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('new_seller_id');
            $table->foreign('new_seller_id')->references('id')->on('users')->cascadeOnDelete();
            // Actor is either a User (PM) or a Company Admin — never both;
            // mirrors created_by/created_by_admin_id's dual-actor split.
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('assigned_by_admin_id')->nullable();
            $table->foreign('assigned_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_seller_assignments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Records WHO last assigned/switched this project's seller_id (added in
// 2026_07_21_100000_add_seller_handoff_columns_to_projects_table.php) and
// WHEN — mirrors the completed_by/completed_by_admin_id dual-actor pattern
// from 2026_07_22_100001_add_lifecycle_columns_to_projects_table.php, since
// either a Company Admin or a permissioned User (PM) can perform this
// action. See App\Services\ProjectSellerAssignmentService.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'seller_assigned_by')) {
                $table->unsignedBigInteger('seller_assigned_by')->nullable()->after('source');
                $table->foreign('seller_assigned_by')->references('id')->on('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('projects', 'seller_assigned_by_admin_id')) {
                $table->unsignedBigInteger('seller_assigned_by_admin_id')->nullable()->after('seller_assigned_by');
                $table->foreign('seller_assigned_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            }
            if (!Schema::hasColumn('projects', 'seller_assigned_at')) {
                $table->timestamp('seller_assigned_at')->nullable()->after('seller_assigned_by_admin_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            foreach (['seller_assigned_by', 'seller_assigned_by_admin_id'] as $fk) {
                if (Schema::hasColumn('projects', $fk)) {
                    $table->dropForeign([$fk]);
                }
            }
            foreach (['seller_assigned_by', 'seller_assigned_by_admin_id', 'seller_assigned_at'] as $col) {
                if (Schema::hasColumn('projects', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

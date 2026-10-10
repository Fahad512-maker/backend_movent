<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Same dual-actor pattern as clients/leads/projects/invoices (see
// 2026_09_11_110000_add_created_by_to_clients_table.php) — employees had no
// "who created this" tracking at all. No reliable backfill source exists for
// existing rows (unlike clients' account_manager), so old employees are left
// with both columns null and just show no creator.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('employees', 'created_by')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->unsignedBigInteger('created_by')->nullable()->after('user_id');
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('employees', 'created_by_admin_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->unsignedBigInteger('created_by_admin_id')->nullable()->after('created_by');
                $table->foreign('created_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'created_by_admin_id')) {
                $table->dropForeign(['created_by_admin_id']);
                $table->dropColumn('created_by_admin_id');
            }
            if (Schema::hasColumn('employees', 'created_by')) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            }
        });
    }
};

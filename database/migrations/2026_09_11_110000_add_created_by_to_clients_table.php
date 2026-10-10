<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// clients had no "who created this" tracking at all — unlike leads/projects/
// invoices, which all use the same created_by (users FK) + created_by_admin_id
// (company_admins FK) dual-actor pattern (see e.g.
// 2026_09_01_100000_add_created_by_admin_id_to_leads_table.php). Adds the
// same pair here so the Client Details page can show "Created By" too.
//
// Backfill: account_manager is set once, at creation, by Api\User\
// ClientController::store() and both LeadController::convert() methods, and
// is never reassigned anywhere in this codebase — so for every row where it's
// already set, it IS the creator. Rows with no account_manager were created
// directly by the Company Admin (Api\Admin\ClientController::store()) or via
// automatic lead-to-client conversion with no assigned staff — attributed to
// the company's own Admin (companies.admin_id), the only actor left standing
// once account_manager is ruled out.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('clients', 'created_by')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->unsignedBigInteger('created_by')->nullable()->after('user_id');
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('clients', 'created_by_admin_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->unsignedBigInteger('created_by_admin_id')->nullable()->after('created_by');
                $table->foreign('created_by_admin_id')->references('id')->on('company_admins')->nullOnDelete();
            });
        }

        $this->backfill();
    }

    private function backfill(): void
    {
        DB::table('clients')
            ->whereNull('created_by')
            ->whereNull('created_by_admin_id')
            ->whereNotNull('account_manager')
            ->update(['created_by' => DB::raw('account_manager')]);

        DB::table('clients as c')
            ->join('companies', 'companies.id', '=', 'c.company_id')
            ->whereNull('c.created_by')
            ->whereNull('c.created_by_admin_id')
            ->whereNotNull('companies.admin_id')
            ->update(['c.created_by_admin_id' => DB::raw('companies.admin_id')]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'created_by_admin_id')) {
                $table->dropForeign(['created_by_admin_id']);
                $table->dropColumn('created_by_admin_id');
            }
            if (Schema::hasColumn('clients', 'created_by')) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Follow-up to 2026_08_19_140000_revoke_unintended_pm_invoice_permission,
// which only revoked canManageProjectInvoices from PMs holding no other part
// of the "Manage Projects" bundle. Company Admin has since decided PM should
// never have invoice-management ability at all, regardless of whatever else
// their "Manage Projects" bundle grants — so this revokes the permission
// from every remaining project_manager user unconditionally. Matches
// canManageProjectInvoices already being removed from Project Manager's
// default bundle in App\Services\RoleDefaultPermissions::MAP.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        DB::table('user_company_permissions')
            ->whereIn('user_id', DB::table('users')->where('role_type', 'project_manager')->pluck('id'))
            ->where('module_key', 'project_management')
            ->where('permission_key', 'canManageProjectInvoices')
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible; this reflects a deliberate policy
        // decision (PM never manages invoices), not a data-fix rollback.
    }
};

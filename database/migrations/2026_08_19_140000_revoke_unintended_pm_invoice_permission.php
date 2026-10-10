<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 2026_08_14_121000_backfill_pm_project_invoice_permission force-granted
// canManageProjectInvoices to every existing PM user, regardless of what the
// Company Admin had actually configured for them. canManageProjectInvoices
// has since been removed from Project Manager's default bundle (see
// App\Services\RoleDefaultPermissions::MAP) because Company Admin does not
// want PM to have invoice-management ability by default.
//
// Revoke the permission only from PMs who show no sign of ever having any
// other part of the "Manage Projects" bundle (canCreateProjects/
// canCreateProjectHandoff/canEditProjects/canCompleteProjects/
// canCloseProjects/canReopenProjects) — for these users the grant could only
// have come from the backfill, never from a deliberate admin choice via the
// "Manage Projects" checkbox. PMs holding any part of the bundle are left
// untouched, since the grant may have been a genuine admin decision.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $bundleKeys = ['canCreateProjects', 'canCreateProjectHandoff', 'canEditProjects', 'canCompleteProjects', 'canCloseProjects', 'canReopenProjects'];

        $pmIds = DB::table('users')->where('role_type', 'project_manager')->pluck('id');

        foreach ($pmIds as $userId) {
            $hasOtherBundleKey = DB::table('user_company_permissions')
                ->where('user_id', $userId)
                ->where('module_key', 'project_management')
                ->whereIn('permission_key', $bundleKeys)
                ->exists();

            if (!$hasOtherBundleKey) {
                DB::table('user_company_permissions')
                    ->where('user_id', $userId)
                    ->where('module_key', 'project_management')
                    ->where('permission_key', 'canManageProjectInvoices')
                    ->delete();
            }
        }
    }

    public function down(): void
    {
        // Intentionally irreversible; this reverses an unintended backfill.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A Lead Manager may only VIEW their own sales target — Company Admin is the
// only role that sets one for them (see App\Services\RoleDefaultPermissions
// ::MAP's 'lead_manager' entry, and the role-level guard in
// Api\User\SalesTargetController::selfEditBlocked()). canUpdateSalesTargets
// ("set my OWN target") used to be a Lead Manager default, so any Lead
// Manager created before this change still holds it. Direct mirror of
// 2026_08_27_100000_revoke_seller_update_sales_targets_permission.php, which
// closed the same path for Sellers.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $leadManagerIds = DB::table('users')->where('role_type', 'lead_manager')->pluck('id');

        DB::table('user_company_permissions')
            ->whereIn('user_id', $leadManagerIds)
            ->where('module_key', 'sales')
            ->where('permission_key', 'canUpdateSalesTargets')
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible — re-granting this automatically would
        // silently reopen the exact self-target-editing path this migration
        // exists to close for every Lead Manager, not just the one who
        // prompted the fix. (The controller's role-level guard would still
        // block it, but the stale grant would wrongly re-show the edit form.)
    }
};

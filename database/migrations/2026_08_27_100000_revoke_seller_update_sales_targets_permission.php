<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A Seller may only VIEW the target set for them (canViewSalesTargets) — see
// App\Services\RoleDefaultPermissions::MAP's 'seller' entry. canUpdateSalesTargets
// used to be a Seller default, so any Seller who signed up before this fix
// may already hold it. Revokes it only from actual 'seller' role_type users;
// never touches a grant a Company Admin may have manually assigned as an
// explicit override (that stays an admin choice, not something this
// migration undoes).
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $sellerIds = DB::table('users')->where('role_type', 'seller')->pluck('id');

        DB::table('user_company_permissions')
            ->whereIn('user_id', $sellerIds)
            ->where('module_key', 'sales')
            ->where('permission_key', 'canUpdateSalesTargets')
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible — re-granting this automatically would
        // silently reopen the exact target-editing path this migration
        // exists to close for every Seller, not just the one who prompted
        // the fix.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Sellers must have zero Task visibility/creation ability (see
// App\Services\RoleDefaultPermissions::MAP's seller entry) — but
// canCreateLinkedProjectTask used to be a Seller default, so any Seller who
// signed up before this fix may already hold it. Revokes it only from actual
// 'seller' role_type users; never touches canViewTasks/canCreateTasks grants
// a Company Admin may have manually assigned to a Seller as an explicit
// override (that stays an admin choice, not something this migration undoes).
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
            ->where('module_key', 'project_management')
            ->where('permission_key', 'canCreateLinkedProjectTask')
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible — re-granting this automatically would
        // silently reopen the exact task-creation path this migration exists
        // to close for every Seller, not just the one who prompted the fix.
    }
};

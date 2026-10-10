<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// canViewAllCompanyClients is now a Lead Manager default going forward (see
// App\Services\RoleDefaultPermissions::MAP) — without it, a client a Seller
// created (account_manager = that Seller, not this Lead Manager) never
// appeared in the Lead Manager's own /clients list, even though they could
// already see every lead company-wide via canViewAllCompanyLeads. Backfill
// existing Lead Managers the same way — only ever INSERTS a missing grant,
// never touches or removes an existing permission row.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $now = now();

        $users = DB::table('users')->where('role_type', 'lead_manager')->get(['id', 'company_id']);

        foreach ($users as $user) {
            DB::table('user_company_permissions')->insertOrIgnore([[
                'user_id'        => $user->id,
                'company_id'     => $user->company_id,
                'module_key'     => 'client',
                'permission_key' => 'canViewAllCompanyClients',
                'created_at'     => $now,
                'updated_at'     => $now,
            ]]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_29_150000_backfill_project_attachment_permissions.php.
    }
};

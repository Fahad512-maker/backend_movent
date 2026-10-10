<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// canManageSalesTargets is now a Lead Manager default going forward (see
// App\Services\RoleDefaultPermissions::MAP and frontend/lib/roleUtils.ts's
// mirrored entry) — the company-wide power to create/update EVERY Seller's
// target, separate from canUpdateSalesTargets (their own target only).
// Backfill existing Lead Managers the same way — only ever INSERTS a missing
// grant, never touches or removes an existing permission row.
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
                'module_key'     => 'sales',
                'permission_key' => 'canManageSalesTargets',
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

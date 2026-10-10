<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // canActivateProjects is now a Seller/Lead Manager default going forward
    // (see App\Services\RoleDefaultPermissions::MAP and
    // frontend/lib/roleUtils.ts's mirrored entries) — a draft project
    // auto-created from a client's payment is most often the Seller's own
    // handed-off deal, and without this permission they could see it
    // (seller_id match) but never activate it themselves. Backfill existing
    // Sellers/Lead Managers the same way — only ever INSERTS a missing
    // grant, never touches or removes an existing permission row.
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $now = now();

        $users = DB::table('users')->whereIn('role_type', ['seller', 'lead_manager'])->get(['id', 'company_id']);

        foreach ($users as $user) {
            DB::table('user_company_permissions')->insertOrIgnore([
                'user_id'        => $user->id,
                'company_id'     => $user->company_id,
                'module_key'     => 'project_management',
                'permission_key' => 'canActivateProjects',
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_29_150000_backfill_project_attachment_permissions.php.
    }
};

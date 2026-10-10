<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// canTransferClients is now a Lead Manager default going forward (see
// App\Services\RoleDefaultPermissions::MAP) — without it, an existing Lead
// Manager created before this permission existed would have no way to
// transfer/assign a client's account manager, even though new Lead Managers
// get it automatically. Backfill existing ones the same way — only ever
// INSERTS a missing grant, never touches or removes an existing permission
// row. Mirrors 2026_08_25_100000_backfill_lead_manager_view_all_clients_permission.php.
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
                'permission_key' => 'canTransferClients',
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

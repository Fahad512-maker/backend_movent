<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// canManageProjectInvoices is a Project Manager default in
// RoleDefaultPermissions. Backfill existing PM users whose permissions were
// created before that default was present or were otherwise stale.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $now = now();
        $rows = [];

        $pms = DB::table('users')->where('role_type', 'project_manager')->get(['id', 'company_id']);
        foreach ($pms as $user) {
            $rows[] = [
                'user_id'        => $user->id,
                'company_id'     => $user->company_id,
                'module_key'     => 'project_management',
                'permission_key' => 'canManageProjectInvoices',
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        if (!empty($rows)) {
            DB::table('user_company_permissions')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible; this is a default PM grant.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// canViewAllCompanyProjects/canViewClosedProjects are now Lead Manager
// defaults going forward (see App\Services\RoleDefaultPermissions::MAP and
// frontend/lib/roleUtils.ts's mirrored entry) — without them, a Lead Manager
// could only see projects where THEY personally were PM/creator/seller/team
// member/task-assignee, never a project that simply belongs to a Seller they
// oversee. Backfill existing Lead Managers the same way — only ever INSERTS
// a missing grant, never touches or removes an existing permission row.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $permissionKeys = ['canViewAllCompanyProjects', 'canViewClosedProjects'];
        $now = now();

        $users = DB::table('users')->where('role_type', 'lead_manager')->get(['id', 'company_id']);

        foreach ($users as $user) {
            $rows = [];
            foreach ($permissionKeys as $permKey) {
                $rows[] = [
                    'user_id'        => $user->id,
                    'company_id'     => $user->company_id,
                    'module_key'     => 'project_management',
                    'permission_key' => $permKey,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }
            DB::table('user_company_permissions')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_29_150000_backfill_project_attachment_permissions.php.
    }
};

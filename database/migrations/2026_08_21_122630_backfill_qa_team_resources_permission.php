<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// canViewTeamResources/canAssignTeamResources are now QA defaults going
// forward (see App\Services\RoleDefaultPermissions::MAP) — the same
// oversight the 2026-08-14 Lead Manager backfill fixed: QA already holds
// canAssignProjectSeller/canViewAllCompanyProjects (more sensitive than
// Team/Resources), so leaving Team/Resources itself out was inconsistent.
// Backfill existing QA users the same way — only ever INSERTS a missing
// grant, never touches or removes an existing permission row.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $permissionKeys = ['canViewTeamResources', 'canAssignTeamResources'];
        $now = now();

        $users = DB::table('users')->where('role_type', 'qa')->get(['id', 'company_id']);

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

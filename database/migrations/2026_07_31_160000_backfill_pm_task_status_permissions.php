<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// canCompleteTasks/canReopenTasks/canOverrideTaskStatus/canAssignProductionTasks/
// canMarkTaskBlocked are now project_manager defaults (see
// App\Services\RoleDefaultPermissions::MAP) as part of the new task
// status-workflow pipeline. Backfills the grant for existing project_manager
// users; only ever INSERTs a missing grant, same precedent as
// 2026_07_31_100000_backfill_pm_delete_any_chat_message_permission.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $now = now();
        $pms = DB::table('users')->where('role_type', 'project_manager')->get(['id', 'company_id']);

        $permKeys = ['canCompleteTasks', 'canReopenTasks', 'canOverrideTaskStatus', 'canAssignProductionTasks', 'canMarkTaskBlocked'];

        $rows = [];
        foreach ($pms as $user) {
            foreach ($permKeys as $permKey) {
                $rows[] = [
                    'user_id'        => $user->id,
                    'company_id'     => $user->company_id,
                    'module_key'     => 'project_management',
                    'permission_key' => $permKey,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }
        }

        if (!empty($rows)) {
            DB::table('user_company_permissions')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_30_130000's rationale.
    }
};

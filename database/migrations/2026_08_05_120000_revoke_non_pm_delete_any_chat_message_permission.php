<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// canDeleteAnyProjectChatMessage was mistakenly included in every project
// role's default permission set (see App\Services\RoleDefaultPermissions::MAP),
// not just project_manager as originally intended (see
// 2026_07_31_100000_backfill_pm_delete_any_chat_message_permission's own
// comment: "every other role can only ever delete their own message").
// Revokes the grant from every already-created non-PM, non-admin user who
// picked it up from the old (wrong) role default — same precedent as that
// migration, just the inverse operation.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $nonPmUserIds = DB::table('users')->where('role_type', '!=', 'project_manager')->pluck('id');

        DB::table('user_company_permissions')
            ->where('module_key', 'project_management')
            ->where('permission_key', 'canDeleteAnyProjectChatMessage')
            ->whereIn('user_id', $nonPmUserIds)
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_30_130000's rationale.
    }
};

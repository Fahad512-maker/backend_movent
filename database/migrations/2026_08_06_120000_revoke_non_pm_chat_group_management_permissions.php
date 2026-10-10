<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// canCreateProjectChatGroup/canManageProjectChatParticipants were mistakenly
// included in every project role's default permission set (see
// App\Services\RoleDefaultPermissions::MAP), not just project_manager as
// Api\User\ProjectMessengerController::isPM()'s own comment says was always
// intended ("the only actors who may create/manage project messenger groups
// & direct chats") — same class of regression as
// 2026_08_05_120000_revoke_non_pm_delete_any_chat_message_permission. Since
// isPM() hard-requires literal PM tier regardless of these two permissions,
// every non-PM holder had a checkbox that silently did nothing. Revokes the
// grant from every already-created non-PM, non-admin user who picked it up
// from the old (wrong) role default.
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
            ->whereIn('permission_key', ['canCreateProjectChatGroup', 'canManageProjectChatParticipants'])
            ->whereIn('user_id', $nonPmUserIds)
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_30_130000's rationale.
    }
};

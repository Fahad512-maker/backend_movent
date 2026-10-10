<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// canDeleteAnyProjectChatMessage is retired: "delete any message in a
// project's chat" is now exclusively Company Admin's authority, exercised
// only from the Admin panel (Api\Admin\ProjectMessengerController::deleteMessage)
// — it is no longer a grantable permission, not even for project_manager (see
// 2026_07_31_100000_backfill_pm_delete_any_chat_message_permission, now
// superseded). Api\User\ProjectMessengerController::deleteMessage() checks
// ownership only, for every role. Revokes every remaining grant of this key.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_company_permissions')) {
            return;
        }

        DB::table('user_company_permissions')
            ->where('module_key', 'project_management')
            ->where('permission_key', 'canDeleteAnyProjectChatMessage')
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_30_130000's rationale.
    }
};

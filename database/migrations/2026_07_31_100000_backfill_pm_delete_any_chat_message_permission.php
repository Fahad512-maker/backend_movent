<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// canDeleteAnyProjectChatMessage is now also a project_manager default (see
// App\Services\RoleDefaultPermissions::MAP) — PM can delete any project chat
// message, same authority as Company Admin; every other role can only ever
// delete their own message (plain ownership check in
// ProjectMessengerController::deleteMessage(), no permission needed for
// that). Backfills the grant for existing project_manager users; only ever
// INSERTS a missing grant, same precedent as
// 2026_07_29_150000_backfill_project_attachment_permissions.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $now = now();
        $pms = DB::table('users')->where('role_type', 'project_manager')->get(['id', 'company_id']);

        $rows = $pms->map(fn ($user) => [
            'user_id'        => $user->id,
            'company_id'     => $user->company_id,
            'module_key'     => 'project_management',
            'permission_key' => 'canDeleteAnyProjectChatMessage',
            'created_at'     => $now,
            'updated_at'     => $now,
        ])->all();

        if (!empty($rows)) {
            DB::table('user_company_permissions')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_30_130000's rationale.
    }
};

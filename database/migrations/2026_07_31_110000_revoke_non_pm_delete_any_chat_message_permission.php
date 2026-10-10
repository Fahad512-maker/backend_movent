<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// canDeleteAnyProjectChatMessage is only ever a Company Admin/Project
// Manager default (see App\Services\RoleDefaultPermissions::MAP) — any
// other role holding it is stray data (in this case, a Developer test
// account was granted it during earlier feature testing), and lets that
// user delete anyone's project chat message, not just their own. Revokes
// the grant from every user whose role_type isn't project_manager; never
// touches project_manager's own grant.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $nonPmUserIds = DB::table('users')->where('role_type', '!=', 'project_manager')->pluck('id');

        DB::table('user_company_permissions')
            ->whereIn('user_id', $nonPmUserIds)
            ->where('module_key', 'project_management')
            ->where('permission_key', 'canDeleteAnyProjectChatMessage')
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_30_130000's rationale.
    }
};

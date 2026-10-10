<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Sellers were deliberately left out of canUploadProjectChatAttachment/
    // canViewProjectChatAttachments in the original backfill
    // (2026_07_30_120003_backfill_project_chat_permissions.php) and in
    // App\Services\RoleDefaultPermissions::MAP, which meant the "Manage
    // Project Chat" bundle checkbox (frontend/lib/simplifiedProjectPermissions.ts)
    // never showed fully checked for a Seller like every other project role
    // does. Now that both defaults grant these two keys going forward,
    // backfill existing Sellers the same way — only ever INSERTS a missing
    // grant, never touches or removes an existing permission row.
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $permissionKeys = ['canUploadProjectChatAttachment', 'canViewProjectChatAttachments'];
        $now = now();

        $users = DB::table('users')->where('role_type', 'seller')->get(['id', 'company_id']);

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

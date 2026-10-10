<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Backfills canView/Upload/Download/DeleteProjectAttachments for existing
    // users of roles that should always have had it but never did (see
    // App\Services\RoleDefaultPermissions::MAP — these keys were missing from
    // every non-Admin role's defaults, so only Company Admin could ever see
    // the Project Attachments section). Only ever INSERTS a missing grant —
    // never touches or removes any existing permission row, and skips a row
    // entirely if that exact grant already exists (unique index on
    // user_id+company_id+module_key+permission_key).
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $grants = [
            'project_manager' => ['canViewProjectAttachments', 'canUploadProjectAttachments', 'canDownloadProjectAttachments', 'canDeleteProjectAttachments'],
            'production'      => ['canViewProjectAttachments', 'canUploadProjectAttachments', 'canDownloadProjectAttachments'],
            'developer'       => ['canViewProjectAttachments', 'canUploadProjectAttachments', 'canDownloadProjectAttachments'],
            'designer'        => ['canViewProjectAttachments', 'canUploadProjectAttachments', 'canDownloadProjectAttachments'],
            'qa'              => ['canViewProjectAttachments', 'canDownloadProjectAttachments'],
            'team_member'     => ['canViewProjectAttachments', 'canUploadProjectAttachments', 'canDownloadProjectAttachments'],
        ];

        $now = now();

        foreach ($grants as $roleType => $permissionKeys) {
            $users = DB::table('users')->where('role_type', $roleType)->get(['id', 'company_id']);

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
    }

    public function down(): void
    {
        // Intentionally irreversible — rolling back would strip a permission
        // that may since have been relied on (or deliberately kept) by a
        // Company Admin; removing it automatically risks silently breaking
        // something a user now depends on.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Backfills the new project-wise messenger permissions for existing
    // users of roles that should have them per App\Services\RoleDefaultPermissions::MAP
    // (new signups get these automatically; existing users need this one-time
    // grant, same pattern as 2026_07_29_150000_backfill_project_attachment_permissions.php).
    // Only ever INSERTS a missing grant — never touches or removes an existing
    // permission row.
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $grants = [
            'project_manager' => [
                'canViewProjectChat', 'canSendProjectChatMessage', 'canCreateProjectChatGroup',
                'canManageProjectChatParticipants', 'canAddSellerToProjectChat',
                'canCreateProjectDirectChat', 'canUploadProjectChatAttachment',
                'canViewProjectChatAttachments', 'canViewProjectChatHistory',
            ],
            'production'  => ['canViewProjectChat', 'canSendProjectChatMessage', 'canUploadProjectChatAttachment', 'canViewProjectChatAttachments'],
            'developer'   => ['canViewProjectChat', 'canSendProjectChatMessage', 'canUploadProjectChatAttachment', 'canViewProjectChatAttachments'],
            'designer'    => ['canViewProjectChat', 'canSendProjectChatMessage', 'canUploadProjectChatAttachment', 'canViewProjectChatAttachments'],
            'qa'          => ['canViewProjectChat', 'canSendProjectChatMessage', 'canUploadProjectChatAttachment', 'canViewProjectChatAttachments'],
            'team_member' => ['canViewProjectChat', 'canSendProjectChatMessage', 'canUploadProjectChatAttachment', 'canViewProjectChatAttachments'],
            'seller'      => ['canViewProjectChat', 'canSendProjectChatMessage'],
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
        // Intentionally irreversible — see 2026_07_29_150000_backfill_project_attachment_permissions.php.
    }
};

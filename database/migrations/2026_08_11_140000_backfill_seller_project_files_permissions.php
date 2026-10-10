<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // App\Services\RoleDefaultPermissions::MAP's Seller bucket now also
    // grants canCreateProjects (cosmetic-only, completes "Manage Projects")
    // and the "Manage Project Files" bundle: canUploadProjectAttachments
    // (functional, now that ProjectAttachmentController::visibleProject()
    // recognizes seller_id), canViewProjectAttachments/
    // canDownloadProjectAttachments (cosmetic — index()/download() branch to
    // the seller-only "visible to client" subset regardless), and
    // canUploadTaskAttachments/canViewTaskAttachments/canDownloadTaskAttachments
    // (cosmetic — Tasks are entirely blocked for role_type='seller').
    // Backfill existing Sellers the same way new signups get it. Only ever
    // INSERTS a missing grant — never touches or removes an existing row.
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('user_company_permissions')) {
            return;
        }

        $permissionKeys = [
            'canCreateProjects',
            'canUploadProjectAttachments', 'canViewProjectAttachments', 'canDownloadProjectAttachments',
            'canUploadTaskAttachments', 'canViewTaskAttachments', 'canDownloadTaskAttachments',
        ];
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

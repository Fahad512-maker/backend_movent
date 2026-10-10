<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // App\Services\ProjectSellerAssignmentService::assign() now also sets
    // project_manager_id to the seller when a project has no PM yet (so the
    // "Project Manager" dropdown/column shows the seller's name instead of
    // "Unassigned" until a real PM is assigned — display only, isPM()/
    // isInternalStaff() still hard-exclude role_type='seller' regardless).
    // That only takes effect on the NEXT assign/switch call — backfill
    // existing projects that already have a seller but no PM the same way.
    public function up(): void
    {
        if (!Schema::hasTable('projects')) {
            return;
        }

        DB::table('projects')
            ->whereNotNull('seller_id')
            ->whereNull('project_manager_id')
            ->update(['project_manager_id' => DB::raw('seller_id')]);
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_29_150000_backfill_project_attachment_permissions.php.
    }
};

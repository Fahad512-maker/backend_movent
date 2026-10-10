<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A Seller can never be a task assignee — the assignee dropdowns and
// assignedToRule() validation in both Api\Admin\TaskController and
// Api\User\TaskController now block this going forward, but a handful of
// existing tasks were already assigned to a Seller before this fix. Clears
// assigned_to (does not delete the task) for any task currently assigned to
// a 'seller' role_type user.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tasks') || !Schema::hasTable('users')) {
            return;
        }

        $sellerIds = DB::table('users')->where('role_type', 'seller')->pluck('id');

        DB::table('tasks')
            ->whereIn('assigned_to', $sellerIds)
            ->update(['assigned_to' => null]);
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_30_130000's rationale;
        // re-assigning automatically would reopen the exact leak this closes.
    }
};

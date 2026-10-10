<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Additive widening, same pattern as 2026_08_18_120000_add_unpaid_to_projects_status_enum.php
// — every existing value is preserved. 'approved_locked' is new: reached only
// via Api\Admin\ProjectController::approveCompletion(), once a 'completed'
// project has been explicitly signed off by Admin. It is a harder read-only
// state than 'completed' (see Project::isLocked()) but distinct from
// 'closed' — a locked project can still be reopened by Admin (or have reopen
// requested by its PM) without going through the Close/Reopen archival flow.
// Placed right after 'completed', since it is reached from there.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE projects MODIFY COLUMN status ENUM('unpaid', 'draft', 'planning', 'active', 'on_hold', 'blocked', 'completed', 'approved_locked', 'cancelled', 'closed') NOT NULL DEFAULT 'planning'");
    }

    public function down(): void
    {
        // Any project still sitting in 'approved_locked' would be truncated by
        // the narrowing below — park them back in 'completed' first so the
        // rollback never silently blanks a status.
        DB::table('projects')->where('status', 'approved_locked')->update(['status' => 'completed']);

        DB::statement("ALTER TABLE projects MODIFY COLUMN status ENUM('unpaid', 'draft', 'planning', 'active', 'on_hold', 'blocked', 'completed', 'cancelled', 'closed') NOT NULL DEFAULT 'planning'");
    }
};

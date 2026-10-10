<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Additive widening, same pattern as 2026_07_22_100000_widen_projects_status_enum.php
// — every existing value is preserved. 'draft' is new: the state a project is
// auto-created in when a client's invoice payment starts it (see
// InvoicePaymentService::handleProjectStartOnPayment()). A draft holds only a
// name; the rest is filled in manually, and it stays invisible to the client
// portal and to staff without canActivateProjects until Company Admin — or a
// sub-user granted that key — activates it.
//
// Deliberately placed FIRST in the enum rather than appended: it precedes
// 'planning' in the real lifecycle, and MySQL orders ENUM by declaration order,
// so any ORDER BY status sorts a draft ahead of a live project.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE projects MODIFY COLUMN status ENUM('draft', 'planning', 'active', 'on_hold', 'blocked', 'completed', 'cancelled', 'closed') NOT NULL DEFAULT 'planning'");
    }

    public function down(): void
    {
        // Any project still sitting in 'draft' would be truncated by the
        // narrowing below — park them in 'planning' first so the rollback
        // never silently blanks a status.
        DB::table('projects')->where('status', 'draft')->update(['status' => 'planning']);

        DB::statement("ALTER TABLE projects MODIFY COLUMN status ENUM('planning', 'active', 'on_hold', 'blocked', 'completed', 'cancelled', 'closed') NOT NULL DEFAULT 'planning'");
    }
};

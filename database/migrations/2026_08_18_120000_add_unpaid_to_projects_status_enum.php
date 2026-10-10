<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Additive widening, same pattern as 2026_08_11_160000_add_draft_to_projects_status_enum.php
// — every existing value is preserved. 'unpaid' is new: the placeholder status
// a Project is created in the moment an invoice is raised in "New Project"
// mode (Api\Admin\InvoiceController::store() / Api\User\InvoiceController::
// store()), before the client has paid anything. It is promoted to 'draft' by
// App\Services\PaymentProjectStartService the moment a qualifying payment
// lands, exactly like a 'draft' would be activated — see Project::isDraft(),
// which treats 'unpaid' identically to 'draft' for every "not real work yet"
// guard (tasks, timesheets, chat, files, client portal visibility, etc).
//
// Placed before 'draft' in the enum, since it precedes it in the real
// lifecycle (unpaid -> draft -> active -> ...).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE projects MODIFY COLUMN status ENUM('unpaid', 'draft', 'planning', 'active', 'on_hold', 'blocked', 'completed', 'cancelled', 'closed') NOT NULL DEFAULT 'planning'");
    }

    public function down(): void
    {
        // Any project still sitting in 'unpaid' would be truncated by the
        // narrowing below — park them in 'draft' first so the rollback never
        // silently blanks a status.
        DB::table('projects')->where('status', 'unpaid')->update(['status' => 'draft']);

        DB::statement("ALTER TABLE projects MODIFY COLUMN status ENUM('draft', 'planning', 'active', 'on_hold', 'blocked', 'completed', 'cancelled', 'closed') NOT NULL DEFAULT 'planning'");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Folds the richer production task-flow (Assigned/In Progress/Blocked/
// Submitted/Revision Requested/Approved/Delivered/Completed/Cancelled) into
// the existing production_queue table instead of a new one. Purely additive —
// all 5 existing enum values are kept, so no existing row or consumer breaks.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE production_queue MODIFY status ENUM('queued','in_progress','blocked','submitted','revision_requested','approved','delivered','completed','rejected','cancelled') NOT NULL DEFAULT 'queued'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE production_queue MODIFY status ENUM('queued','in_progress','submitted','approved','rejected') NOT NULL DEFAULT 'queued'");
    }
};

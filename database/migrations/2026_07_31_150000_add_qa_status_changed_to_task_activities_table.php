<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Additive widening of task_activities.type, same pattern as
// 2026_07_30_190000_add_commented_revision_to_task_activities_table.php.
// 'qa_status_changed' backs the new QA-pipeline transitions (ready_for_qa,
// in_qa, qa_failed, qa_passed, ready_for_production, in_production) so they
// get their own distinct History entry type instead of overloading the
// generic 'status_changed'.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE task_activities MODIFY COLUMN type ENUM('created', 'updated', 'status_changed', 'assigned', 'completed', 'commented', 'revision', 'qa_status_changed') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE task_activities MODIFY COLUMN type ENUM('created', 'updated', 'status_changed', 'assigned', 'completed', 'commented', 'revision') NOT NULL");
    }
};

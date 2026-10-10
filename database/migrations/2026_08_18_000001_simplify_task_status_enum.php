<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Collapses the QA-pipeline stages out of tasks.status — Task status changes
// are now a simple free jump for any allowed actor (see
// App\Services\TaskStatusService::canChangeTaskStatus()), not a guided
// matrix, so the dedicated QA states (ready_for_qa/in_qa/qa_failed/
// qa_passed) and their supporting columns have no reader left anywhere in
// the app. Irreversible — the QA-verdict/timestamp data is genuinely gone
// once dropped, matching this codebase's existing precedent for permission/
// schema cleanup migrations (e.g. 2026_08_14_110000_backfill_production_
// queue_for_existing_tasks.php's down()).
return new class extends Migration
{
    public function up(): void
    {
        // Reassign existing rows BEFORE narrowing the enum, or the ALTER
        // below fails (or silently blanks the column) on any row still
        // sitting in a status about to become illegal.
        DB::table('tasks')->whereIn('status', ['ready_for_qa', 'in_qa', 'qa_failed'])->update(['status' => 'in_progress']);
        DB::table('tasks')->where('status', 'qa_passed')->update(['status' => 'ready_for_production']);

        DB::statement("ALTER TABLE tasks MODIFY COLUMN status ENUM(
            'todo', 'in_progress', 'blocked', 'ready_for_production', 'in_production',
            'review', 'completed', 'cancelled'
        ) NOT NULL DEFAULT 'todo'");

        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'qa_assigned_to')) {
                $table->dropConstrainedForeignId('qa_assigned_to');
            }
            foreach (['qa_status', 'ready_for_qa_at', 'qa_started_at', 'qa_completed_at'] as $col) {
                if (Schema::hasColumn('tasks', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        // Irreversible — see class comment.
    }
};

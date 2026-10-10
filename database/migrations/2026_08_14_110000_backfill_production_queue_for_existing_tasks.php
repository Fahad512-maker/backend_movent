<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// TaskStatusService::applyTransition() now creates/refreshes a
// production_queue row the moment a task reaches 'ready_for_production' —
// before this fix, nothing ever created one, so any task that had already
// reached that status (or moved further, into 'in_production'/'completed')
// was invisible in every Production Queue view despite genuinely being
// there. One-off catch-up for those already-affected tasks; only ever
// inserts a row where none exists yet for that task_id.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tasks') || !Schema::hasTable('production_queue')) {
            return;
        }

        $queueStatusFor = [
            'ready_for_production' => 'queued',
            'in_production'        => 'in_progress',
            'completed'            => 'completed',
        ];

        $tasks = DB::table('tasks')
            ->whereNotNull('ready_for_production_at')
            ->whereIn('status', array_keys($queueStatusFor))
            ->get(['id', 'status', 'production_assigned_to', 'ready_for_production_at']);

        foreach ($tasks as $task) {
            if (DB::table('production_queue')->where('task_id', $task->id)->exists()) {
                continue;
            }

            DB::table('production_queue')->insert([
                'task_id'     => $task->id,
                'assigned_to' => $task->production_assigned_to,
                'status'      => $queueStatusFor[$task->status],
                'started_at'  => in_array($task->status, ['in_production', 'completed'], true) ? $task->ready_for_production_at : null,
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_29_150000_backfill_project_attachment_permissions.php.
    }
};

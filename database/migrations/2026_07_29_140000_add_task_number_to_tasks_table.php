<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tasks')) {
            return;
        }

        // Step 1 — add the columns nullable first (no unique constraint yet),
        // so this is safe to run against a table that already has rows.
        Schema::table('tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('tasks', 'task_sequence')) {
                $table->unsignedInteger('task_sequence')->nullable()->after('progress');
            }
            if (!Schema::hasColumn('tasks', 'task_number')) {
                $table->string('task_number')->nullable()->after('task_sequence');
            }
        });

        // Step 2 — backfill existing tasks project-wise, ordered by creation
        // so existing chronology maps to TASK-0001, 0002, ... Only ever
        // touches task_number/task_sequence — title/status/assigned data is
        // never modified, nothing is deleted.
        $projectIds = DB::table('tasks')->whereNull('task_number')->distinct()->pluck('project_id');

        foreach ($projectIds as $projectId) {
            $sequence = (int) DB::table('tasks')->where('project_id', $projectId)->max('task_sequence');

            $taskIds = DB::table('tasks')
                ->where('project_id', $projectId)
                ->whereNull('task_number')
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id');

            foreach ($taskIds as $taskId) {
                $sequence++;
                DB::table('tasks')->where('id', $taskId)->update([
                    'task_sequence' => $sequence,
                    'task_number'   => sprintf('PRJ-%d-TASK-%04d', $projectId, $sequence),
                ]);
            }
        }

        // Step 3 — now that every row has a value, enforce uniqueness. A
        // unique index on a nullable column still allows any future NULLs
        // (MySQL treats each NULL as distinct), but after the backfill above
        // no row should have one. project_id+task_sequence guards the
        // generator's own invariant; task_number is unique on its own since
        // it already embeds the project id (itself globally unique).
        if (!Schema::hasIndex('tasks', 'tasks_project_id_task_sequence_unique')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->unique(['project_id', 'task_sequence'], 'tasks_project_id_task_sequence_unique');
            });
        }

        if (!Schema::hasIndex('tasks', 'tasks_task_number_unique')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->unique('task_number', 'tasks_task_number_unique');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('tasks')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasIndex('tasks', 'tasks_task_number_unique')) {
                $table->dropUnique('tasks_task_number_unique');
            }
            if (Schema::hasIndex('tasks', 'tasks_project_id_task_sequence_unique')) {
                $table->dropUnique('tasks_project_id_task_sequence_unique');
            }
            if (Schema::hasColumn('tasks', 'task_number')) {
                $table->dropColumn('task_number');
            }
            if (Schema::hasColumn('tasks', 'task_sequence')) {
                $table->dropColumn('task_sequence');
            }
        });
    }
};

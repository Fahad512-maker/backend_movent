<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// task_assigned notifications never carried a 'link' in their `data` JSON
// (Admin\TaskController::store()/update() and User\TaskController::store()/
// update() all built the same shape without one), so clicking the bell
// notification did nothing — Navbar.tsx only pushes router to n.data.link.
// Backfills the link onto every already-created task_assigned row using its
// existing project_id/task_id, same shape the controllers now emit going
// forward: "/projects/{project_id}/tasks/{task_id}".
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notifications')) {
            return;
        }

        DB::table('notifications')->where('type', 'task_assigned')->orderBy('id')
            ->each(function ($row) {
                $data = json_decode($row->data, true) ?? [];
                if (!empty($data['link']) || empty($data['project_id']) || empty($data['task_id'])) {
                    return;
                }
                $data['link'] = "/projects/{$data['project_id']}/tasks/{$data['task_id']}";
                DB::table('notifications')->where('id', $row->id)->update(['data' => json_encode($data)]);
            });
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_30_130000's rationale.
    }
};

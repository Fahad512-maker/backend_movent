<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The standalone QA/Production handoff notifications
// (Api\User\TaskController::update()'s $qaHandoffStandalone/
// $productionHandoffStandalone branches) built their 'data' the same
// link-less shape task_assigned had (see
// 2026_08_05_130000_backfill_task_assigned_notification_link) — clicking
// them did nothing. Backfills the link onto every already-created
// task_ready_for_qa/task_ready_for_production row using its existing
// project_id/task_id.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notifications')) {
            return;
        }

        DB::table('notifications')->whereIn('type', ['task_ready_for_qa', 'task_ready_for_production'])->orderBy('id')
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

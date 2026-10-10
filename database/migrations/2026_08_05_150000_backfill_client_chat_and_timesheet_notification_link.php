<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Two more raw Notification::create() call sites never carried a 'link':
// - client_chat_message (Api\Client\ChatController notifying staff, and
//   Api\Admin\ClientChatController/Api\User\ClientChatController notifying
//   the client-portal user) — staff recipients link to /clients/{id}
//   (via chat_threads.linked_to_id), client-portal recipients to /client/chat.
// - timesheet_approved/timesheet_rejected (Api\Admin\TimesheetController)
//   — links to the timesheet's task.
// Backfills every already-created row of both types.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notifications')) {
            return;
        }

        $clientUserIds = DB::table('clients')->whereNotNull('user_id')->pluck('user_id')->flip();

        DB::table('notifications')->where('type', 'client_chat_message')->orderBy('id')
            ->each(function ($row) use ($clientUserIds) {
                $data = json_decode($row->data, true) ?? [];
                if (!empty($data['link'])) {
                    return;
                }
                if (isset($clientUserIds[$row->user_id])) {
                    $data['link'] = '/client/chat';
                } elseif (!empty($data['thread_id'])) {
                    $thread = DB::table('chat_threads')->where('id', $data['thread_id'])->where('linked_to_type', 'Client')->first();
                    if (!$thread) return;
                    $data['link'] = "/clients/{$thread->linked_to_id}";
                } else {
                    return;
                }
                DB::table('notifications')->where('id', $row->id)->update(['data' => json_encode($data)]);
            });

        DB::table('notifications')->whereIn('type', ['timesheet_approved', 'timesheet_rejected'])->orderBy('id')
            ->each(function ($row) {
                $data = json_decode($row->data, true) ?? [];
                if (!empty($data['link']) || empty($data['timesheet_id'])) {
                    return;
                }
                $timesheet = DB::table('timesheets')->where('id', $data['timesheet_id'])->first();
                if (!$timesheet) return;
                $task = DB::table('tasks')->where('id', $timesheet->task_id)->first();
                if (!$task) return;
                $data['link'] = "/projects/{$task->project_id}/tasks/{$task->id}";
                DB::table('notifications')->where('id', $row->id)->update(['data' => json_encode($data)]);
            });
    }

    public function down(): void
    {
        // Intentionally irreversible — see 2026_07_30_130000's rationale.
    }
};

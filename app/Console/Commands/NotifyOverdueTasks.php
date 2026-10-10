<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Task;
use App\Services\EventMailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * "Task Overdue" — there was no trigger for this event at all before: a task
 * simply sat past its due date and nobody was told. Runs daily alongside
 * invoices:mark-overdue (see routes/console.php), which needs the server cron
 * entry `* * * * * php artisan schedule:run` to actually fire.
 *
 * Fires ONCE per task, not once per day: unlike an invoice, a task has no
 * status flip to ride on, so the guard is "has this task already produced a
 * task_overdue notification row?". A task that is edited to a new future due
 * date and goes overdue again therefore stays quiet — deliberate, since the
 * alternative is a daily nag for every stale task in the company.
 */
class NotifyOverdueTasks extends Command
{
    protected $signature = 'tasks:notify-overdue';

    protected $description = 'Notify assignees (in-app + email) about tasks that have passed their due date';

    public function handle(): int
    {
        $tasks = Task::query()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('assigned_to')
            ->with(['project:id,name,company_id,status', 'assignedTo:id,name'])
            ->get();

        $sent = 0;

        foreach ($tasks as $task) {
            // A task on a closed/completed project isn't anybody's problem
            // anymore, whatever its own status says.
            if (!$task->project || in_array($task->project->status, ['completed', 'closed', 'cancelled'], true)) {
                continue;
            }

            $alreadyNotified = Notification::where('type', 'task_overdue')
                ->where('entity_type', 'Task')
                ->where('entity_id', $task->id)
                ->exists();

            if ($alreadyNotified) {
                continue;
            }

            try {
                Notification::create([
                    'user_id'     => $task->assigned_to,
                    'company_id'  => $task->project->company_id,
                    'type'        => 'task_overdue',
                    'title'       => 'Task overdue',
                    'body'        => "Task {$task->task_number} - \"{$task->title}\" on \"{$task->project->name}\" is past its due date.",
                    'entity_type' => 'Task',
                    'entity_id'   => $task->id,
                    'data'        => [
                        'project_id' => $task->project_id,
                        'task_id'    => $task->id,
                        'link'       => "/projects/{$task->project_id}/tasks/{$task->id}",
                    ],
                ]);

                $recipients = [['user_id' => $task->assigned_to]];
                // The PM owns the schedule, so they hear about it too — the
                // fan-out de-dupes if the PM IS the assignee.
                if ($task->project->project_manager_id) {
                    $recipients[] = ['user_id' => $task->project->project_manager_id];
                }

                EventMailService::taskOverdue($task, $task->project, $recipients);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('[tasks:notify-overdue] failed for task ' . $task->id . ': ' . $e->getMessage());
            }
        }

        $this->info("Notified {$sent} overdue task(s) out of {$tasks->count()} found.");

        return self::SUCCESS;
    }
}

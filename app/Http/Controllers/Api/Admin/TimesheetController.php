<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Task;
use App\Models\Timesheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TimesheetController extends Controller
{
    private function admin()   { return auth('admin')->user(); }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    private function baseQuery()
    {
        return Timesheet::whereHas('task.project', fn($q) => $q->whereIn('company_id', $this->companyIds()))
            ->with(['task:id,title,project_id', 'task.project:id,name,company_id', 'user:id,name', 'approvedBy:id,name']);
    }

    public function index(Request $request): JsonResponse
    {
        $q = $this->baseQuery();

        if ($request->filled('project_id')) $q->whereHas('task', fn($t) => $t->where('project_id', $request->project_id));
        if ($request->filled('task_id'))    $q->where('task_id', $request->task_id);
        if ($request->filled('user_id'))    $q->where('user_id', $request->user_id);
        if ($request->filled('status'))     $q->where('status', $request->status);

        return ApiResponse::success($q->orderByDesc('log_date')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_id'      => ['required', 'integer', 'exists:tasks,id'],
            'user_id'      => ['required', 'integer', 'exists:users,id'],
            'hours_logged' => ['required', 'numeric', 'min:0.1', 'max:24'],
            'log_date'     => ['required', 'date'],
            'notes'        => ['nullable', 'string'],
        ]);

        $task = Task::whereHas('project', fn($q) => $q->whereIn('company_id', $this->companyIds()))
            ->with('project:id,status')
            ->findOrFail($validated['task_id']);

        // Time is logged against a task, and a draft project can't have tasks
        // (see TaskController::store()) — this covers a task that predates
        // that guard, so no hours can be booked to work that hasn't started.
        if ($task->project?->isDraft()) {
            return ApiResponse::error(Project::DRAFT_BLOCKED_MESSAGE, 422);
        }

        $validated['status'] = 'pending';
        $timesheet = Timesheet::create($validated);

        return ApiResponse::success($timesheet->fresh(['task', 'user']), 'Time logged', 201);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', 'in:approved,rejected']]);

        $timesheet = $this->baseQuery()->findOrFail($id);
        $timesheet->update([
            'status'      => $validated['status'],
            // approved_by FKs to `users`; Company Admin actor isn't a User row
            'approved_by' => null,
        ]);

        Notification::create([
            'user_id'    => $timesheet->user_id,
            'company_id' => $timesheet->task->project->company_id,
            'type'       => 'timesheet_' . $validated['status'],
            'title'      => 'Timesheet ' . $validated['status'],
            'body'       => "Your timesheet for \"{$timesheet->task->title}\" was {$validated['status']}.",
            'data'       => ['timesheet_id' => $timesheet->id, 'link' => "/projects/{$timesheet->task->project_id}/tasks/{$timesheet->task_id}"],
        ]);

        return ApiResponse::success($timesheet, 'Timesheet ' . $validated['status']);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->baseQuery();

        if ($request->filled('project_id')) $rows->whereHas('task', fn($t) => $t->where('project_id', $request->project_id));

        $rows = $rows->orderBy('log_date')->get();

        $callback = function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Project', 'Task', 'User', 'Hours', 'Status', 'Notes']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->log_date->format('Y-m-d'),
                    $r->task->project->name ?? '',
                    $r->task->title ?? '',
                    $r->user->name ?? '',
                    $r->hours_logged,
                    $r->status,
                    $r->notes,
                ]);
            }
            fclose($out);
        };

        return response()->streamDownload($callback, 'timesheets.csv', ['Content-Type' => 'text/csv']);
    }
}

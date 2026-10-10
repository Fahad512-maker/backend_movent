<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\UserCompanyPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimesheetController extends Controller
{
    private function user() { return auth('sanctum')->user(); }

    private function can(string $permKey): bool
    {
        $user = $this->user();
        return UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('module_key', 'project_management')
            ->where('permission_key', $permKey)
            ->exists();
    }

    // Own timesheets, or all timesheets in the company if canViewTimesheets is granted.
    public function index(Request $request): JsonResponse
    {
        $user = $this->user();

        $q = Timesheet::whereHas('task.project', fn($p) => $p->where('company_id', $user->company_id))
            ->with(['task:id,title,project_id', 'task.project:id,name', 'user:id,name']);

        if (!$this->can('canViewTimesheets')) {
            $q->where('user_id', $user->id);
        } elseif ($request->filled('user_id')) {
            $q->where('user_id', $request->user_id);
        }

        if ($request->filled('status')) $q->where('status', $request->status);

        return ApiResponse::success($q->orderByDesc('log_date')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user();

        $validated = $request->validate([
            'task_id'      => ['required', 'integer', 'exists:tasks,id'],
            'hours_logged' => ['required', 'numeric', 'min:0.1', 'max:24'],
            'log_date'     => ['required', 'date'],
            'notes'        => ['nullable', 'string'],
        ]);

        $task = Task::whereHas('project', fn($p) => $p->where('company_id', $user->company_id))
            ->with('project:id,status')
            ->findOrFail($validated['task_id']);

        // Time is logged against a task, and a draft project can't have tasks
        // (see TaskController::store()) — this covers a task that predates
        // that guard, so no hours can be booked to work that hasn't started.
        if ($task->project?->isDraft()) {
            return ApiResponse::error(Project::DRAFT_BLOCKED_MESSAGE, 422);
        }

        if ($task->project?->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        $validated['user_id'] = $user->id;
        $validated['status']  = 'pending';

        $timesheet = Timesheet::create($validated);

        return ApiResponse::success($timesheet->fresh('task'), 'Time logged', 201);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canApproveTimesheets')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $user = $this->user();
        $validated = $request->validate(['status' => ['required', 'in:approved,rejected']]);

        $timesheet = Timesheet::whereHas('task.project', fn($p) => $p->where('company_id', $user->company_id))
            ->with('task.project:id,status')
            ->findOrFail($id);

        if ($timesheet->task?->project?->isLocked()) {
            return ApiResponse::error(Project::LOCKED_MESSAGE, 422);
        }

        $timesheet->update(['status' => $validated['status'], 'approved_by' => $user->id]);

        return ApiResponse::success($timesheet, 'Timesheet ' . $validated['status']);
    }
}

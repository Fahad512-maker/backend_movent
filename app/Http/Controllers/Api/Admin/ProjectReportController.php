<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\Timesheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProjectReportController extends Controller
{
    use ScopesToActiveCompany;

    private function admin()   { return auth('admin')->user(); }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    private function projectIds(): array
    {
        return Project::whereIn('company_id', $this->activeCompanyIds())->pluck('id')->toArray();
    }

    public function statusReport(): JsonResponse
    {
        $counts = Project::whereIn('company_id', $this->activeCompanyIds())
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return ApiResponse::success($counts);
    }

    public function taskStatusReport(): JsonResponse
    {
        $counts = Task::whereIn('project_id', $this->projectIds())
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return ApiResponse::success($counts);
    }

    public function workloadReport(): JsonResponse
    {
        // Built as a plain array rather than returning the Eloquent collection
        // directly — the `assignedTo` relation serializes to the JSON key
        // "assigned_to", which would silently overwrite the raw grouped column.
        $workload = Task::whereIn('project_id', $this->projectIds())
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, COUNT(*) as total, SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed')
            ->groupBy('assigned_to')
            ->with('assignedTo:id,name')
            ->get()
            ->map(fn ($row) => [
                'user_id'   => $row->assigned_to,
                'user'      => $row->assignedTo,
                'total'     => (int) $row->total,
                'completed' => (int) $row->completed,
            ]);

        return ApiResponse::success($workload);
    }

    public function timesheetReport(): JsonResponse
    {
        $hours = Timesheet::whereHas('task', fn ($q) => $q->whereIn('project_id', $this->projectIds()))
            ->selectRaw('user_id, task_id, SUM(hours_logged) as total_hours')
            ->groupBy('user_id', 'task_id')
            ->with(['user:id,name', 'task:id,title,task_number,project_id', 'task.project:id,name'])
            ->get();

        return ApiResponse::success($hours);
    }

    public function overdueReport(): JsonResponse
    {
        $overdue = Task::whereIn('project_id', $this->projectIds())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->with(['assignedTo:id,name', 'project:id,name'])
            ->orderBy('due_date')
            ->get();

        return ApiResponse::success($overdue);
    }

    public function completedProjectsReport(): JsonResponse
    {
        $completed = Project::whereIn('company_id', $this->activeCompanyIds())
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->with(['client:id,name', 'projectManager:id,name'])
            ->orderByDesc('completed_at')
            ->get()
            ->map(function ($p) {
                $p->duration_days = $p->start_date ? $p->start_date->diffInDays($p->completed_at) : null;
                return $p;
            });

        return ApiResponse::success($completed);
    }
}

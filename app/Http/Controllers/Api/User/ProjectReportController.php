<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\UserCompanyPermission;
use App\Support\PermissionDebug;
use Illuminate\Http\JsonResponse;

// Lightweight staff-facing reports — 3 of the Admin side's 6 reports, scoped
// to the same project-visibility rule as Api\User\ProjectController, per the
// agreed "lightweight, not full parity" scope for the staff portal.
class ProjectReportController extends Controller
{
    private function user() { return auth('sanctum')->user(); }

    private function can(string $permKey): bool
    {
        $user = $this->user();
        $result = UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('module_key', 'project_management')
            ->where('permission_key', $permKey)
            ->exists();
        PermissionDebug::log($user->id, $user->company_id, $user->role_type, 'project_management', $permKey, $result);
        return $result;
    }

    // Mirrors Api\User\ProjectController::visibleProjects().
    private function visibleProjectIds(): array
    {
        $user = $this->user();
        $base = Project::where('company_id', $user->company_id);

        if (!$this->can('canViewAllCompanyProjects')) {
            $base->where(function ($q) use ($user) {
                $q->where('project_manager_id', $user->id)
                  ->orWhereHas('teamMembers', fn ($t) => $t->where('user_id', $user->id))
                  ->orWhereHas('tasks', fn ($t) => $t->where('assigned_to', $user->id));
            });
        }

        return $base->pluck('id')->all();
    }

    public function statusReport(): JsonResponse
    {
        if (!$this->can('canViewProjectReports')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $counts = Project::whereIn('id', $this->visibleProjectIds())
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return ApiResponse::success($counts);
    }

    public function taskStatusReport(): JsonResponse
    {
        if (!$this->can('canViewProjectReports') && !$this->can('canViewTaskReports')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $counts = Task::whereIn('project_id', $this->visibleProjectIds())
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return ApiResponse::success($counts);
    }

    public function overdueReport(): JsonResponse
    {
        if (!$this->can('canViewProjectReports')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $overdue = Task::whereIn('project_id', $this->visibleProjectIds())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->with(['assignedTo:id,name', 'project:id,name'])
            ->orderBy('due_date')
            ->get();

        return ApiResponse::success($overdue);
    }
}

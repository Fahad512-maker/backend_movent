<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Models\CompanyModule;
use App\Models\Project;
use App\Models\Task;
use App\Models\UserCompanyPermission;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

// Dev-only diagnostic endpoint for the "sub-user sees nothing" class of bugs —
// never registered/reachable outside a local environment (see routes/api.php).
class DebugController extends Controller
{
    public function access(): JsonResponse
    {
        if (!app()->isLocal()) {
            abort(404);
        }

        $user = auth('sanctum')->user();

        $companyModules = CompanyModule::where('company_id', $user->company_id)
            ->where('is_enabled', true)
            ->pluck('module_key');

        $permissions = UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->get(['module_key', 'permission_key', 'company_user_id']);

        $hasAllCompanyScope = $permissions->contains(fn ($p) => $p->permission_key === 'canViewAllCompanyProjects');

        $visibleProjectsQuery = Project::where('company_id', $user->company_id);
        if (!$hasAllCompanyScope) {
            $visibleProjectsQuery->where(function ($q) use ($user) {
                $q->where('project_manager_id', $user->id)
                  ->orWhereHas('teamMembers', fn ($t) => $t->where('user_id', $user->id))
                  ->orWhereHas('tasks', fn ($t) => $t->where('assigned_to', $user->id));
            });
        }
        $visibleProjectIds = $visibleProjectsQuery->pluck('id');

        return ApiResponse::success([
            'user_id'                 => $user->id,
            'company_id'              => $user->company_id,
            'role_type'               => $user->role_type,
            'active_company_modules'  => $companyModules,
            'permissions'             => $permissions,
            'has_all_company_scope'   => $hasAllCompanyScope,
            'visible_project_ids'     => $visibleProjectIds,
            'visible_projects_count'  => $visibleProjectIds->count(),
            'assigned_tasks_count'    => Task::where('assigned_to', $user->id)->count(),
        ]);
    }
}

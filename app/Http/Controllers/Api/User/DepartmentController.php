<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\User\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Recruitment;
use App\Models\UserCompanyPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// Staff-side mirror of Api\Admin\DepartmentController — gated by its own
// canManageDepartments (view/create/edit/delete all share it, same pattern
// as ShiftController) rather than the general canView/Create/Edit/Delete
// Employees bucket. Department CRUD affects the department list every
// employee/recruitment record draws from, so it's deliberately excluded
// from the hr role's default permission set (see ModuleCatalog.php) —
// Admin-only unless explicitly granted.
class DepartmentController extends Controller
{
    use ScopesToActiveCompany;

    private function user() { return auth('sanctum')->user(); }

    private function can(string $permKey): bool
    {
        $user = $this->user();
        return UserCompanyPermission::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('module_key', 'hr')
            ->where('permission_key', $permKey)
            ->exists();
    }

    private function department(int $id): Department
    {
        return Department::where('company_id', $this->activeCompanyId())->findOrFail($id);
    }

    public function index(): JsonResponse
    {
        // Read-only listing also opens to anyone who can create/edit an
        // employee — they need the department names to fill that dropdown
        // even without canManageDepartments (CRUD on departments themselves
        // stays gated to that permission alone, below).
        if (!$this->can('canManageDepartments') && !$this->can('canCreateEmployees') && !$this->can('canEditEmployees')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companyId = $this->activeCompanyId();
        $departments = Department::where('company_id', $companyId)
            ->with('company:id,name')
            ->orderBy('name')
            ->get();

        $counts = Employee::where('company_id', $companyId)
            ->whereIn('department', $departments->pluck('name'))
            ->selectRaw('department, COUNT(*) as aggregate')
            ->groupBy('department')
            ->pluck('aggregate', 'department');

        $departments->each(function (Department $department) use ($counts) {
            $department->setAttribute('employee_count', (int) ($counts->get($department->name) ?? 0));
        });

        return ApiResponse::success($departments);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->can('canManageDepartments')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companyId = $this->activeCompanyId();
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('departments', 'name')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $validated['name'] = trim($validated['name']);
        $validated['company_id'] = $companyId;
        $department = Department::create($validated);

        return ApiResponse::success($department->load('company:id,name'), 'Department created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canManageDepartments')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $department = $this->department($id);
        $validated = $request->validate([
            'name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('departments', 'name')
                    ->where(fn ($query) => $query->where('company_id', $department->company_id))
                    ->ignore($department->id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        if (array_key_exists('name', $validated)) {
            $validated['name'] = trim($validated['name']);
        }

        DB::transaction(function () use ($department, $validated) {
            $oldName = $department->name;
            $department->update($validated);

            if ($department->name !== $oldName) {
                Employee::where('company_id', $department->company_id)
                    ->where('department', $oldName)
                    ->update(['department' => $department->name]);
                Recruitment::where('company_id', $department->company_id)
                    ->where('department', $oldName)
                    ->update(['department' => $department->name]);
            }
        });

        return ApiResponse::success($department->fresh()->load('company:id,name'), 'Department updated');
    }

    public function destroy(int $id): JsonResponse
    {
        if (!$this->can('canManageDepartments')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $department = $this->department($id);
        $employeeCount = Employee::where('company_id', $department->company_id)
            ->where('department', $department->name)
            ->count();

        if ($employeeCount > 0) {
            throw ValidationException::withMessages([
                'department' => "Move {$employeeCount} assigned employee(s) before deleting this department.",
            ]);
        }

        $department->delete();

        return ApiResponse::success(null, 'Department deleted');
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Recruitment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    use ScopesToActiveCompany;

    private function admin()
    {
        return auth('admin')->user();
    }

    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    private function department(int $id): Department
    {
        return Department::whereIn('company_id', $this->activeCompanyIds())->findOrFail($id);
    }

    public function index(Request $request): JsonResponse
    {
        $companyIds = $this->activeCompanyIds();
        if ($request->filled('company_id')) {
            $companyId = (int) $request->input('company_id');
            abort_unless(in_array($companyId, $this->companyIds(), true), 404);
            $companyIds = [$companyId];
        }

        $query = Department::whereIn('company_id', $companyIds)
            ->with('company:id,name')
            ->orderBy('name');

        $departments = $query->get();
        $counts = Employee::whereIn('company_id', $departments->pluck('company_id')->unique())
            ->whereIn('department', $departments->pluck('name'))
            ->selectRaw('company_id, department, COUNT(*) as aggregate')
            ->groupBy('company_id', 'department')
            ->get()
            ->keyBy(fn ($row) => $row->company_id . '|' . $row->department);

        $departments->each(function (Department $department) use ($counts) {
            $key = $department->company_id . '|' . $department->name;
            $department->setAttribute('employee_count', (int) ($counts->get($key)?->aggregate ?? 0));
        });

        return ApiResponse::success($departments);
    }

    public function store(Request $request): JsonResponse
    {
        $companyIds = $this->activeCompanyIds();
        $validated = $request->validate([
            'company_id' => ['required', 'integer', Rule::in($companyIds)],
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('departments', 'name')->where(
                    fn ($query) => $query->where('company_id', $request->integer('company_id'))
                ),
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $validated['name'] = trim($validated['name']);
        $department = Department::create($validated);

        return ApiResponse::success($department->load('company:id,name'), 'Department created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
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

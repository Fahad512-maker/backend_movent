<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\User\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\UserCompanyPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Staff-side mirror of Api\Admin\ShiftController — gated by its own
// canManageShifts (view/create/edit/delete all share it) rather than the
// general canView/Create/Edit/DeleteEmployees bucket. Shift definitions
// drive attendance/lateness/payroll-deduction calculations company-wide,
// so it's deliberately excluded from the hr role's default permission set
// (see ModuleCatalog.php) — Admin-only unless explicitly granted.
class ShiftController extends Controller
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

    private function shift(int $id): Shift
    {
        return Shift::where('company_id', $this->activeCompanyId())->findOrFail($id);
    }

    public function index(): JsonResponse
    {
        if (!$this->can('canManageShifts')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $shifts = Shift::where('company_id', $this->activeCompanyId())
            ->orderBy('start_time')
            ->get();

        return ApiResponse::success($shifts);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->can('canManageShifts')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $validated = $request->validate([
            'name'                  => ['required', 'string', 'max:100'],
            'start_time'            => ['required', 'date_format:H:i'],
            'end_time'              => ['required', 'date_format:H:i'],
            'grace_period_minutes'  => ['nullable', 'integer', 'min:0', 'max:180'],
        ]);

        $validated['company_id'] = $this->activeCompanyId();

        $shift = Shift::create($validated);

        return ApiResponse::success($shift, 'Shift created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canManageShifts')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $shift = $this->shift($id);

        $validated = $request->validate([
            'name'                  => ['sometimes', 'string', 'max:100'],
            'start_time'            => ['sometimes', 'date_format:H:i'],
            'end_time'              => ['sometimes', 'date_format:H:i'],
            'grace_period_minutes'  => ['nullable', 'integer', 'min:0', 'max:180'],
        ]);

        $shift->update($validated);

        return ApiResponse::success($shift, 'Shift updated');
    }

    public function destroy(int $id): JsonResponse
    {
        if (!$this->can('canManageShifts')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $shift = $this->shift($id);
        $shift->delete();

        return ApiResponse::success(null, 'Shift deleted');
    }
}

<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\User\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\AttendancePolicy;
use App\Models\UserCompanyPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Staff-side mirror of Api\Admin\AttendancePolicyController — gated by its
// own canManageAttendancePolicy (both view and edit), not the general
// canViewEmployees/canEditEmployees bucket Shifts/Holidays use. This screen
// sets salary deduction %/free-allowance rules that affect every employee's
// payroll, so it's deliberately excluded from the hr role's default
// permission set (see ModuleCatalog.php) — Admin-only unless a Company
// Admin explicitly grants this specific permission to a staff user.
class AttendancePolicyController extends Controller
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

    public function show(): JsonResponse
    {
        if (!$this->can('canManageAttendancePolicy')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $policy = AttendancePolicy::firstOrCreate(['company_id' => $this->activeCompanyId()]);

        return ApiResponse::success($policy);
    }

    public function update(Request $request): JsonResponse
    {
        if (!$this->can('canManageAttendancePolicy')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $validated = $request->validate([
            'half_day_threshold_minutes'  => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'full_day_threshold_minutes'  => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'overtime_enabled'            => ['sometimes', 'boolean'],
            'overtime_threshold_minutes'  => ['nullable', 'integer', 'min:0', 'max:1440'],
            'default_grace_period_minutes'=> ['sometimes', 'integer', 'min:0', 'max:180'],
            'default_working_days'        => ['nullable', 'array'],
            'default_working_days.*'      => ['integer', 'min:1', 'max:7'],
            'late_deduction_percent'      => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'half_day_deduction_percent'  => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'absent_deduction_percent'    => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'off_day_deduction_percent'   => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'free_late_allowed'           => ['sometimes', 'integer', 'min:0', 'max:31'],
            'free_half_day_allowed'       => ['sometimes', 'integer', 'min:0', 'max:31'],
            'free_absent_allowed'         => ['sometimes', 'integer', 'min:0', 'max:31'],
            'free_off_day_allowed'        => ['sometimes', 'integer', 'min:0', 'max:31'],
        ]);

        $policy = AttendancePolicy::firstOrCreate(['company_id' => $this->activeCompanyId()]);
        $policy->update($validated);

        return ApiResponse::success($policy->fresh(), 'Attendance policy updated');
    }
}

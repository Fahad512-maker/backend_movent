<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\AttendancePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Company-wide attendance rules: half/full-day worked-hours thresholds,
// grace period/working-days defaults, overtime config, and the salary
// deduction percentages + free-allowance counts applied when Payroll is
// generated (see App\Services\AttendanceDeductionCalculator). One row per
// company, lazily created on first access — no seeder needed.
class AttendancePolicyController extends Controller
{
    use ScopesToActiveCompany;

    private function admin() { return auth('admin')->user(); }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    public function show(): JsonResponse
    {
        $companyId = $this->activeCompanyIds()[0] ?? null;
        if (!$companyId) return ApiResponse::error('No active company found for your account.', 422);

        $policy = AttendancePolicy::firstOrCreate(['company_id' => $companyId]);

        return ApiResponse::success($policy);
    }

    public function update(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyIds()[0] ?? null;
        if (!$companyId) return ApiResponse::error('No active company found for your account.', 422);

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

        $policy = AttendancePolicy::firstOrCreate(['company_id' => $companyId]);
        $policy->update($validated);

        return ApiResponse::success($policy->fresh(), 'Attendance policy updated');
    }
}

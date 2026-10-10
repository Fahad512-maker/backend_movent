<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\User\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\Payroll;
use App\Models\SystemAuditLog;
use App\Models\UserCompanyPermission;
use App\Services\AttendanceDeductionCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Staff-side mirror of Api\Admin\PayrollController — View/Update/Process
// only (no Create/mark-paid-as-separate-action), matching the granular
// permission set this role was actually given: canViewPayroll,
// canUpdatePayroll, canProcessPayroll. Payroll draft creation and the
// draft -> processed -> paid lifecycle's final "mark paid" step stay
// Company-Admin-only, same as before.
class PayrollController extends Controller
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

    private function logActivity(int $companyId, string $action, int $employeeId, array $newValues = []): void
    {
        SystemAuditLog::create([
            'company_id'  => $companyId,
            'user_id'     => $this->user()->id,
            'action'      => $action,
            'module_key'  => 'hr',
            'entity_type' => 'Employee',
            'entity_id'   => $employeeId,
            'new_values'  => $newValues,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        if (!$this->can('canViewPayroll')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companyId = $this->activeCompanyId();

        $q = Payroll::whereHas('employee', fn ($e) => $e->where('company_id', $companyId))
            ->with('employee:id,name,employee_code,department');

        if ($request->filled('employee_id')) $q->where('employee_id', $request->employee_id);
        if ($request->filled('month_year'))  $q->where('month_year', $request->month_year);
        if ($request->filled('status'))      $q->where('status', $request->status);

        return ApiResponse::success($q->orderByDesc('created_at')->get());
    }

    public function deductionPreview(Request $request): JsonResponse
    {
        if (!$this->can('canViewPayroll')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companyId = $this->activeCompanyId();

        $validated = $request->validate([
            'employee_id'  => ['required', 'integer'],
            'month_year'   => ['required', 'date_format:Y-m'],
            'basic_salary' => ['required', 'numeric', 'min:0'],
        ]);

        $employee = Employee::where('company_id', $companyId)->findOrFail($validated['employee_id']);
        $company = Company::findOrFail($companyId);
        $calculator = new AttendanceDeductionCalculator($company);

        return ApiResponse::success($calculator->compute($employee, $validated['month_year'], (float) $validated['basic_salary']));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canUpdatePayroll')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companyId = $this->activeCompanyId();

        $payroll = Payroll::whereHas('employee', fn ($e) => $e->where('company_id', $companyId))
            ->with('employee')
            ->findOrFail($id);

        if ($payroll->status !== 'draft') {
            return ApiResponse::error('Only a draft payroll record can be edited.', 422);
        }

        $validated = $request->validate([
            'basic_salary'                   => ['sometimes', 'numeric', 'min:0'],
            'allowances'                      => ['sometimes', 'numeric', 'min:0'],
            'deductions'                      => ['sometimes', 'numeric', 'min:0'],
            'attendance_deduction'            => ['sometimes', 'numeric', 'min:0'],
            'attendance_deduction_breakdown'  => ['sometimes', 'nullable', 'array'],
        ]);

        $payroll->fill($validated);
        $payroll->net_pay = $payroll->basic_salary + $payroll->allowances - $payroll->deductions - $payroll->attendance_deduction;
        $payroll->save();

        $this->logActivity($payroll->employee->company_id, 'payroll_updated', $payroll->employee_id, ['payroll_id' => $payroll->id, ...$validated]);

        return ApiResponse::success($payroll->fresh(['employee:id,name']), 'Payroll record updated');
    }

    public function process(int $id): JsonResponse
    {
        if (!$this->can('canProcessPayroll')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companyId = $this->activeCompanyId();

        $payroll = Payroll::whereHas('employee', fn ($e) => $e->where('company_id', $companyId))
            ->with('employee')
            ->findOrFail($id);

        $payroll->update([
            'net_pay'      => $payroll->basic_salary + $payroll->allowances - $payroll->deductions - $payroll->attendance_deduction,
            'status'       => 'processed',
            'processed_by' => $this->user()->id,
        ]);

        $this->logActivity($payroll->employee->company_id, 'payroll_processed', $payroll->employee_id, ['payroll_id' => $payroll->id, 'net_pay' => $payroll->net_pay]);

        if ($payroll->employee->user_id) {
            Notification::create([
                'user_id'    => $payroll->employee->user_id,
                'company_id' => $payroll->employee->company_id,
                'type'       => 'payroll_processed',
                'title'      => 'Payroll processed',
                'body'       => "Your payroll for {$payroll->month_year} has been processed — net pay {$payroll->net_pay}.",
                'data'       => ['payroll_id' => $payroll->id],
            ]);
        }

        return ApiResponse::success($payroll->fresh(['employee:id,name']), 'Payroll processed');
    }

    public function payslip(int $id): JsonResponse
    {
        if (!$this->can('canViewPayroll')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companyId = $this->activeCompanyId();

        $payroll = Payroll::whereHas('employee', fn ($e) => $e->where('company_id', $companyId))
            ->with('employee.company:id,name')
            ->findOrFail($id);

        return ApiResponse::success($payroll);
    }
}

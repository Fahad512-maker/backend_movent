<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\Payroll;
use App\Models\SystemAuditLog;
use App\Services\AttendanceDeductionCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    use ScopesToActiveCompany;

    private function admin() { return auth('admin')->user(); }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    private function logActivity(int $companyId, string $action, int $employeeId, array $newValues = []): void
    {
        SystemAuditLog::create([
            'company_id'  => $companyId,
            'user_id'     => null,
            'action'      => $action,
            'module_key'  => 'hr',
            'entity_type' => 'Employee',
            'entity_id'   => $employeeId,
            'new_values'  => $newValues,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $companyIds = $this->activeCompanyIds();

        $q = Payroll::whereHas('employee', fn ($e) => $e->whereIn('company_id', $companyIds))
            ->with('employee:id,name,employee_code,department');

        if ($request->filled('employee_id')) $q->where('employee_id', $request->employee_id);
        if ($request->filled('month_year'))  $q->where('month_year', $request->month_year);
        if ($request->filled('status'))      $q->where('status', $request->status);

        return ApiResponse::success($q->orderByDesc('created_at')->get());
    }

    // Preview only — computes what the attendance-based deduction WOULD be
    // for this employee+month at the given salary, without creating/
    // touching any Payroll record. The create form calls this to prefill
    // `attendance_deduction` before the admin submits (and can still edit
    // it either way).
    public function deductionPreview(Request $request): JsonResponse
    {
        $companyIds = $this->companyIds();

        $validated = $request->validate([
            'employee_id'  => ['required', 'integer'],
            'month_year'   => ['required', 'date_format:Y-m'],
            'basic_salary' => ['required', 'numeric', 'min:0'],
        ]);

        $employee = Employee::whereIn('company_id', $companyIds)->findOrFail($validated['employee_id']);
        $company = Company::findOrFail($employee->company_id);
        $calculator = new AttendanceDeductionCalculator($company);

        return ApiResponse::success($calculator->compute($employee, $validated['month_year'], (float) $validated['basic_salary']));
    }

    public function store(Request $request): JsonResponse
    {
        $companyIds = $this->companyIds();

        $validated = $request->validate([
            'employee_id'                    => ['required', 'integer'],
            'month_year'                     => ['required', 'string', 'max:10'],
            'basic_salary'                   => ['required', 'numeric', 'min:0'],
            'allowances'                      => ['nullable', 'numeric', 'min:0'],
            'deductions'                      => ['nullable', 'numeric', 'min:0'],
            'attendance_deduction'            => ['nullable', 'numeric', 'min:0'],
            'attendance_deduction_breakdown'  => ['nullable', 'array'],
        ]);

        $employee = Employee::whereIn('company_id', $companyIds)->findOrFail($validated['employee_id']);

        $validated['allowances'] ??= 0;
        $validated['deductions'] ??= 0;
        $validated['attendance_deduction'] ??= 0;
        $validated['net_pay'] = $validated['basic_salary'] + $validated['allowances'] - $validated['deductions'] - $validated['attendance_deduction'];
        $validated['status'] = 'draft';

        $payroll = Payroll::create($validated);

        $this->logActivity($employee->company_id, 'payroll_created', $employee->id, $validated);

        return ApiResponse::success($payroll->load('employee:id,name'), 'Payroll record created', 201);
    }

    // "Update Payroll" — draft figures only. Once processed/paid the record
    // is a historical statement, not a form; editing it after the fact would
    // silently rewrite what an employee was told they'd be paid.
    public function update(Request $request, int $id): JsonResponse
    {
        $companyIds = $this->companyIds();

        $payroll = Payroll::whereHas('employee', fn ($e) => $e->whereIn('company_id', $companyIds))
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

    // "Process Payroll" — draft -> processed, recomputes net_pay.
    public function process(int $id): JsonResponse
    {
        $companyIds = $this->companyIds();

        $payroll = Payroll::whereHas('employee', fn ($e) => $e->whereIn('company_id', $companyIds))
            ->with('employee')
            ->findOrFail($id);

        $payroll->update([
            'net_pay' => $payroll->basic_salary + $payroll->allowances - $payroll->deductions - $payroll->attendance_deduction,
            'status'  => 'processed',
            // processed_by FKs to `users`; Company Admin actor isn't a User row.
            'processed_by' => null,
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

    public function markPaid(int $id): JsonResponse
    {
        $companyIds = $this->companyIds();

        $payroll = Payroll::whereHas('employee', fn ($e) => $e->whereIn('company_id', $companyIds))
            ->with('employee')
            ->findOrFail($id);

        $payroll->update(['status' => 'paid', 'paid_at' => now()]);

        $this->logActivity($payroll->employee->company_id, 'payroll_paid', $payroll->employee_id, ['payroll_id' => $payroll->id]);

        if ($payroll->employee->user_id) {
            Notification::create([
                'user_id'    => $payroll->employee->user_id,
                'company_id' => $payroll->employee->company_id,
                'type'       => 'payroll_paid',
                'title'      => 'Payroll paid',
                'body'       => "Your payroll for {$payroll->month_year} has been paid — {$payroll->net_pay}.",
                'data'       => ['payroll_id' => $payroll->id],
            ]);
        }

        return ApiResponse::success($payroll->fresh(['employee:id,name']), 'Payroll marked as paid');
    }

    // "Generate Payslips" — on-screen printable detail, no PDF library.
    public function payslip(int $id): JsonResponse
    {
        $companyIds = $this->companyIds();

        $payroll = Payroll::whereHas('employee', fn ($e) => $e->whereIn('company_id', $companyIds))
            ->with('employee.company:id,name')
            ->findOrFail($id);

        return ApiResponse::success($payroll);
    }
}

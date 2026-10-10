<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Notification;
use App\Models\SystemAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LeaveController extends Controller
{
    use ScopesToActiveCompany;

    private function admin() { return auth('admin')->user(); }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    public function index(Request $request): JsonResponse
    {
        $companyIds = $this->activeCompanyIds();

        $q = LeaveRequest::whereHas('employee', fn ($e) => $e->whereIn('company_id', $companyIds))
            ->with('employee:id,name,employee_code,department');

        if ($request->filled('employee_id')) $q->where('employee_id', $request->employee_id);
        if ($request->filled('status'))      $q->where('status', $request->status);

        return ApiResponse::success($q->orderByDesc('created_at')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $companyIds = $this->companyIds();

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'leave_type'  => ['required', 'in:annual,sick,casual,maternity,unpaid'],
            'from_date'   => ['required', 'date'],
            'to_date'     => ['required', 'date', 'after_or_equal:from_date'],
            'reason'      => ['nullable', 'string', 'max:1000'],
        ]);

        $employee = Employee::whereIn('company_id', $companyIds)->findOrFail($validated['employee_id']);

        $validated['total_days'] = Carbon::parse($validated['from_date'])->diffInDays(Carbon::parse($validated['to_date'])) + 1;
        $validated['status'] = 'pending';

        $leave = LeaveRequest::create($validated);

        SystemAuditLog::create([
            'company_id'  => $employee->company_id,
            'user_id'     => null,
            'action'      => 'leave_requested',
            'module_key'  => 'hr',
            'entity_type' => 'Employee',
            'entity_id'   => $employee->id,
            'new_values'  => $validated,
        ]);

        return ApiResponse::success($leave->load('employee:id,name'), 'Leave request created', 201);
    }

    // "Approve / Reject Leave"
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $companyIds = $this->companyIds();

        $leave = LeaveRequest::whereHas('employee', fn ($e) => $e->whereIn('company_id', $companyIds))
            ->with('employee')
            ->findOrFail($id);

        $validated = $request->validate(['status' => ['required', 'in:approved,rejected']]);

        // approved_by FKs to `users`; Company Admin actor isn't a User row.
        $leave->update(['status' => $validated['status'], 'approved_by' => null]);

        SystemAuditLog::create([
            'company_id'  => $leave->employee->company_id,
            'user_id'     => null,
            'action'      => 'leave_' . $validated['status'],
            'module_key'  => 'hr',
            'entity_type' => 'Employee',
            'entity_id'   => $leave->employee_id,
            'new_values'  => ['status' => $validated['status']],
        ]);

        // Only when the employee has a linked staff account (employees.user_id)
        // — notifications.user_id is a non-nullable FK to users, so an
        // unlinked employee simply can't receive one (mirrors the same guard
        // Admin\TaskController uses before its own Notification::create calls).
        if ($leave->employee->user_id) {
            Notification::create([
                'user_id'    => $leave->employee->user_id,
                'company_id' => $leave->employee->company_id,
                'type'       => 'leave_' . $validated['status'],
                'title'      => $validated['status'] === 'approved' ? 'Leave request approved' : 'Leave request rejected',
                'body'       => sprintf(
                    'Your %s leave from %s to %s was %s.',
                    $leave->leave_type,
                    $leave->from_date,
                    $leave->to_date,
                    $validated['status']
                ),
                // No `link`: there is no staff-facing HR page today for a
                // notification to deep-link into (see EmployeeController
                // audit note — employee.user_id exists but nothing in the
                // User-guard API reads it yet), so this stays informational
                // only rather than pointing at a route that 404s.
                'data' => ['leave_id' => $leave->id],
            ]);
        }

        return ApiResponse::success($leave->fresh(['employee:id,name']), 'Leave ' . $validated['status']);
    }
}

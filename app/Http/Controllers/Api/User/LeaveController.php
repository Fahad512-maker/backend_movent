<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\User\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Notification;
use App\Models\SystemAuditLog;
use App\Models\UserCompanyPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// Staff-side mirror of Api\Admin\LeaveController — same three actions
// (View/Apply/Approve), gated by the real canViewLeave/canApplyLeave/
// canApproveLeave permissions instead of the admin guard's blanket bypass.
//
// store() stays the same workflow as the Admin side (file a request against
// any employee in the active company) rather than a separate self-only
// "apply for my own leave" path — per the "don't build a second workflow"
// rule, this IS the one leave-request workflow, now reachable by a
// permission-holding staff member instead of Company-Admin-only.
class LeaveController extends Controller
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

    public function index(Request $request): JsonResponse
    {
        if (!$this->can('canViewLeave')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companyId = $this->activeCompanyId();

        $q = LeaveRequest::whereHas('employee', fn ($e) => $e->where('company_id', $companyId))
            ->with('employee:id,name,employee_code,department');

        if ($request->filled('employee_id')) $q->where('employee_id', $request->employee_id);
        if ($request->filled('status'))      $q->where('status', $request->status);

        return ApiResponse::success($q->orderByDesc('created_at')->get());
    }

    // "Apply" — files a leave request.
    public function store(Request $request): JsonResponse
    {
        if (!$this->can('canApplyLeave')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companyId = $this->activeCompanyId();

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'leave_type'  => ['required', 'in:annual,sick,casual,maternity,unpaid'],
            'from_date'   => ['required', 'date'],
            'to_date'     => ['required', 'date', 'after_or_equal:from_date'],
            'reason'      => ['nullable', 'string', 'max:1000'],
        ]);

        $employee = Employee::where('company_id', $companyId)->findOrFail($validated['employee_id']);

        $validated['total_days'] = Carbon::parse($validated['from_date'])->diffInDays(Carbon::parse($validated['to_date'])) + 1;
        $validated['status'] = 'pending';

        $leave = LeaveRequest::create($validated);

        SystemAuditLog::create([
            'company_id'  => $employee->company_id,
            'user_id'     => $this->user()->id,
            'action'      => 'leave_requested',
            'module_key'  => 'hr',
            'entity_type' => 'Employee',
            'entity_id'   => $employee->id,
            'new_values'  => $validated,
        ]);

        return ApiResponse::success($leave->load('employee:id,name'), 'Leave request created', 201);
    }

    // "Approve / Reject"
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canApproveLeave')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companyId = $this->activeCompanyId();

        $leave = LeaveRequest::whereHas('employee', fn ($e) => $e->where('company_id', $companyId))
            ->with('employee')
            ->findOrFail($id);

        $validated = $request->validate(['status' => ['required', 'in:approved,rejected']]);

        $leave->update(['status' => $validated['status'], 'approved_by' => $this->user()->id]);

        SystemAuditLog::create([
            'company_id'  => $leave->employee->company_id,
            'user_id'     => $this->user()->id,
            'action'      => 'leave_' . $validated['status'],
            'module_key'  => 'hr',
            'entity_type' => 'Employee',
            'entity_id'   => $leave->employee_id,
            'new_values'  => ['status' => $validated['status']],
        ]);

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
                'data' => ['leave_id' => $leave->id],
            ]);
        }

        return ApiResponse::success($leave->fresh(['employee:id,name']), 'Leave ' . $validated['status']);
    }
}

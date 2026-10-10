<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\User\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\Recruitment;
use App\Models\SystemAuditLog;
use App\Models\UserCompanyPermission;
use Illuminate\Http\JsonResponse;

// Staff-side mirror of Api\Admin\HrDashboardController — same shape, scoped
// to the single active company (ScopesToActiveCompany) rather than every
// company a Company Admin owns, and gated by the real per-user
// canViewHRDashboard permission instead of the admin guard's blanket bypass.
class HrDashboardController extends Controller
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

    public function index(): JsonResponse
    {
        if (!$this->can('canViewHRDashboard')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companyId = $this->activeCompanyId();

        $employees = Employee::where('company_id', $companyId);

        $today = now()->toDateString();
        $todayAttendance = Attendance::whereHas('employee', fn ($e) => $e->where('company_id', $companyId))
            ->where('date', $today)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return ApiResponse::success([
            'total_employees'      => (clone $employees)->count(),
            'active_employees'     => (clone $employees)->where('status', 'active')->count(),
            'on_leave_employees'   => (clone $employees)->where('status', 'on_leave')->count(),
            'terminated_employees' => (clone $employees)->where('status', 'terminated')->count(),
            'pending_leave_requests' => LeaveRequest::whereHas('employee', fn ($e) => $e->where('company_id', $companyId))
                ->where('status', 'pending')->count(),
            'attendance_today' => [
                'present' => $todayAttendance['present'] ?? 0,
                'absent'  => $todayAttendance['absent'] ?? 0,
                'late'    => $todayAttendance['late'] ?? 0,
            ],
            'open_recruitment_postings' => Recruitment::where('company_id', $companyId)->where('status', 'open')->count(),
            'payroll_pending' => Payroll::whereHas('employee', fn ($e) => $e->where('company_id', $companyId))
                ->where('status', 'draft')->count(),
            'recent_activity' => SystemAuditLog::where('company_id', $companyId)
                ->where('module_key', 'hr')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(['id', 'action', 'entity_type', 'entity_id', 'created_at']),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\Recruitment;
use App\Models\SystemAuditLog;
use Illuminate\Http\JsonResponse;

class HrDashboardController extends Controller
{
    use ScopesToActiveCompany;

    private function admin() { return auth('admin')->user(); }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    public function index(): JsonResponse
    {
        // The dashboard is a summary view, same family as the Employees/
        // Attendance/etc. list endpoints — it follows the top bar's active
        // company filter (activeCompanyIds()), same as InvoiceController's
        // index(), rather than always summing every company this admin owns.
        $companyIds = $this->activeCompanyIds();

        $employees = Employee::whereIn('company_id', $companyIds);

        $today = now()->toDateString();
        $todayAttendance = Attendance::whereHas('employee', fn ($e) => $e->whereIn('company_id', $companyIds))
            ->where('date', $today)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return ApiResponse::success([
            'total_employees'      => (clone $employees)->count(),
            'active_employees'     => (clone $employees)->where('status', 'active')->count(),
            'on_leave_employees'   => (clone $employees)->where('status', 'on_leave')->count(),
            'terminated_employees' => (clone $employees)->where('status', 'terminated')->count(),
            'pending_leave_requests' => LeaveRequest::whereHas('employee', fn ($e) => $e->whereIn('company_id', $companyIds))
                ->where('status', 'pending')->count(),
            'attendance_today' => [
                'present' => $todayAttendance['present'] ?? 0,
                'absent'  => $todayAttendance['absent'] ?? 0,
                'late'    => $todayAttendance['late'] ?? 0,
            ],
            'open_recruitment_postings' => Recruitment::whereIn('company_id', $companyIds)->where('status', 'open')->count(),
            'payroll_pending' => Payroll::whereHas('employee', fn ($e) => $e->whereIn('company_id', $companyIds))
                ->where('status', 'draft')->count(),
            // Last 10 HR audit-log entries for the active company scope —
            // the dashboard's own "what just happened" feed, distinct from
            // the shared notification bell (which mixes in every module).
            'recent_activity' => SystemAuditLog::whereIn('company_id', $companyIds)
                ->where('module_key', 'hr')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(['id', 'action', 'entity_type', 'entity_id', 'created_at']),
        ]);
    }
}

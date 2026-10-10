<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Lightweight HR reports — matches the "lightweight, not exhaustive"
// precedent set by Admin\ProjectReportController.
class HrReportController extends Controller
{
    use ScopesToActiveCompany;

    private function admin() { return auth('admin')->user(); }
    private function companyIds(): array
    {
        return $this->admin()->companies()->pluck('id')->toArray();
    }

    public function headcountByDepartment(): JsonResponse
    {
        $counts = Employee::whereIn('company_id', $this->activeCompanyIds())
            ->where('status', '!=', 'terminated')
            ->selectRaw('COALESCE(department, "Unassigned") as department, COUNT(*) as total')
            ->groupBy('department')
            ->pluck('total', 'department');

        return ApiResponse::success($counts);
    }

    public function attendanceSummary(Request $request): JsonResponse
    {
        $companyIds = $this->activeCompanyIds();

        $q = Attendance::whereHas('employee', fn ($e) => $e->whereIn('company_id', $companyIds));

        if ($request->filled('from')) $q->where('date', '>=', $request->from);
        if ($request->filled('to'))   $q->where('date', '<=', $request->to);

        $counts = $q->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return ApiResponse::success($counts);
    }

    public function leaveSummary(): JsonResponse
    {
        $companyIds = $this->activeCompanyIds();

        $byStatus = LeaveRequest::whereHas('employee', fn ($e) => $e->whereIn('company_id', $companyIds))
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $byType = LeaveRequest::whereHas('employee', fn ($e) => $e->whereIn('company_id', $companyIds))
            ->selectRaw('leave_type, COUNT(*) as total')->groupBy('leave_type')->pluck('total', 'leave_type');

        return ApiResponse::success(['by_status' => $byStatus, 'by_type' => $byType]);
    }
}

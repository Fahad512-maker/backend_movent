<?php

namespace App\Http\Controllers\Api\User;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\User\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\SystemAuditLog;
use App\Models\UserCompanyPermission;
use App\Services\AttendanceActionService;
use App\Services\AttendanceCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

// Staff-side mirror of Api\Admin\AttendanceController — same behaviour,
// scoped to the caller's active company, gated by canViewAttendance/
// canUpdateAttendance instead of the admin guard's blanket bypass. Adds
// self check-in/check-out for the logged-in user's own linked Employee
// record (Admin has no equivalent — a Company Admin isn't an Employee).
class AttendanceController extends Controller
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

    private function ownEmployee(): ?Employee
    {
        return Employee::where('user_id', $this->user()->id)
            ->where('company_id', $this->activeCompanyId())
            ->first();
    }

    public function index(Request $request): JsonResponse
    {
        if (!$this->can('canViewAttendance')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companyId = $this->activeCompanyId();

        $q = Attendance::where('company_id', $companyId)
            ->with('employee:id,name,employee_code,department');

        if ($request->filled('employee_id')) $q->where('employee_id', $request->employee_id);
        if ($request->filled('department'))  $q->where('department', $request->department);
        if ($request->filled('shift_id'))    $q->where('shift_id', $request->shift_id);
        if ($request->filled('date'))        $q->whereDate('date', $request->date);
        if ($request->filled('from'))        $q->where('date', '>=', $request->from);
        if ($request->filled('to'))          $q->where('date', '<=', $request->to);
        if ($request->filled('status')) {
            $statuses = is_array($request->status) ? $request->status : explode(',', $request->status);
            $q->whereIn('status', $statuses);
        }

        return ApiResponse::success($q->orderByDesc('date')->get());
    }

    public function mark(Request $request): JsonResponse
    {
        if (!$this->can('canUpdateAttendance')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companyId = $this->activeCompanyId();

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'date'        => ['required', 'date'],
            'status'      => ['nullable', 'in:present,absent,late,half_day,holiday,leave,off_day'],
            'check_in'    => ['nullable', 'date_format:H:i'],
            'check_out'   => ['nullable', 'date_format:H:i'],
            'notes'       => ['nullable', 'string'],
        ]);

        $employee = Employee::where('company_id', $companyId)->findOrFail($validated['employee_id']);
        $company = Company::findOrFail($companyId);
        $calculator = new AttendanceCalculator($company);

        $row = $calculator->compute($employee, Carbon::parse($validated['date']), $validated['check_in'] ?? null, $validated['check_out'] ?? null, true);
        $isOverride = !empty($validated['status']);
        if ($isOverride) $row['status'] = $validated['status'];
        $row['source'] = 'manual';
        $row['is_manual_override'] = $isOverride;
        $row['notes'] = $validated['notes'] ?? null;

        $attendance = Attendance::updateOrCreate(
            ['employee_id' => $employee->id, 'date' => $validated['date']],
            $row
        );

        SystemAuditLog::create([
            'company_id'  => $employee->company_id,
            'user_id'     => $this->user()->id,
            'action'      => 'attendance_marked',
            'module_key'  => 'hr',
            'entity_type' => 'Employee',
            'entity_id'   => $employee->id,
            'new_values'  => $validated,
        ]);

        return ApiResponse::success($attendance->load('employee:id,name,employee_code'), 'Attendance marked');
    }

    public function checkInEmployee(Request $request, int $employeeId): JsonResponse
    {
        if (!$this->can('canUpdateAttendance')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $validated = $request->validate([
            'time_mode' => ['nullable', 'in:current,manual'],
            'time'      => ['nullable', 'date_format:H:i'],
            'date'      => ['nullable', 'date'],
            'reason'    => ['nullable', 'string', 'max:500'],
            'note'      => ['nullable', 'string', 'max:1000'],
        ]);

        $employee = Employee::where('company_id', $this->activeCompanyId())
            ->with('shift')
            ->findOrFail($employeeId);
        $company = Company::findOrFail($employee->company_id);

        $attendance = (new AttendanceActionService($company))
            ->checkIn($employee, $validated, 'user:' . $this->user()->id);

        return ApiResponse::success($attendance, 'Checked in');
    }

    public function checkOutEmployee(Request $request, int $employeeId): JsonResponse
    {
        if (!$this->can('canUpdateAttendance')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $validated = $request->validate([
            'time_mode' => ['nullable', 'in:current,manual'],
            'time'      => ['nullable', 'date_format:H:i'],
            'reason'    => ['nullable', 'string', 'max:500'],
            'note'      => ['nullable', 'string', 'max:1000'],
        ]);

        $employee = Employee::where('company_id', $this->activeCompanyId())
            ->with('shift')
            ->findOrFail($employeeId);
        $company = Company::findOrFail($employee->company_id);

        $attendance = (new AttendanceActionService($company))
            ->checkOut($employee, $validated, 'user:' . $this->user()->id);

        return ApiResponse::success($attendance, 'Checked out');
    }

    public function correct(Request $request, int $id): JsonResponse
    {
        if (!$this->can('canUpdateAttendance')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companyId = $this->activeCompanyId();
        $attendance = Attendance::where('company_id', $companyId)->findOrFail($id);

        $validated = $request->validate([
            'status'    => ['nullable', 'in:present,absent,late,half_day,holiday,leave,off_day'],
            'check_in'  => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'notes'     => ['nullable', 'string'],
            'reason'    => ['required', 'string', 'max:500'],
        ]);

        $old = $attendance->only(['status', 'check_in', 'check_out', 'worked_minutes', 'late_by_minutes', 'overtime_minutes', 'notes']);

        $employee = Employee::where('company_id', $companyId)->findOrFail($attendance->employee_id);
        $company = Company::findOrFail($companyId);
        $calculator = new AttendanceCalculator($company);

        $checkIn = array_key_exists('check_in', $validated) ? $validated['check_in'] : $attendance->check_in;
        $checkOut = array_key_exists('check_out', $validated) ? $validated['check_out'] : $attendance->check_out;

        $row = $calculator->compute($employee, Carbon::parse($attendance->date), $checkIn, $checkOut, true);
        if (!empty($validated['status'])) $row['status'] = $validated['status'];
        $row['source'] = 'manual';
        $row['is_manual_override'] = true;
        if (array_key_exists('notes', $validated)) $row['notes'] = $validated['notes'];

        $attendance->update($row);

        SystemAuditLog::create([
            'company_id'  => $attendance->company_id,
            'user_id'     => $this->user()->id,
            'action'      => 'attendance_corrected',
            'module_key'  => 'hr',
            'entity_type' => 'Attendance',
            'entity_id'   => $attendance->id,
            'old_values'  => $old,
            'new_values'  => array_merge(
                $attendance->fresh()->only(['status', 'check_in', 'check_out', 'worked_minutes', 'late_by_minutes', 'overtime_minutes', 'notes']),
                ['reason' => $validated['reason']]
            ),
        ]);

        return ApiResponse::success($attendance->fresh()->load('employee:id,name,employee_code'), 'Attendance corrected');
    }

    public function history(int $id): JsonResponse
    {
        if (!$this->can('canViewAttendance')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companyId = $this->activeCompanyId();
        Attendance::where('company_id', $companyId)->findOrFail($id);

        $logs = SystemAuditLog::where('entity_type', 'Attendance')
            ->where('entity_id', $id)
            ->where('action', 'attendance_corrected')
            ->orderByDesc('created_at')
            ->get(['id', 'user_id', 'old_values', 'new_values', 'created_at']);

        return ApiResponse::success($logs);
    }

    public function summary(Request $request): JsonResponse
    {
        if (!$this->can('canViewAttendance')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $company = Company::findOrFail($this->activeCompanyId());
        $tz = $company->timezone ?: 'UTC';
        $date = $request->filled('date') ? Carbon::parse($request->date, $tz) : Carbon::now($tz);
        $calculator = new AttendanceCalculator($company);

        $counts = [
            'total_employees' => 0, 'present' => 0, 'late' => 0, 'absent' => 0,
            'leave' => 0, 'half_day' => 0, 'holiday' => 0, 'off_day' => 0, 'not_checked_in' => 0,
            'currently_working' => 0, 'checked_out' => 0, 'off_today' => 0,
        ];

        $employees = Employee::where('company_id', $company->id)->where('status', 'active')->get();
        $counts['total_employees'] = $employees->count();

        $existing = Attendance::where('company_id', $company->id)
            ->whereDate('date', $date->toDateString())
            ->get()->keyBy('employee_id');

        foreach ($employees as $employee) {
            $row = $existing->get($employee->id);
            $status = $row ? $row->status : $calculator->compute($employee, $date, null, null, null)['status'];

            if ($row?->check_in && !$row?->check_out) $counts['currently_working']++;
            if ($row?->check_in && $row?->check_out) $counts['checked_out']++;
            if ($status === 'off_day') $counts['off_today']++;
            if ($status === 'pending') { $counts['not_checked_in']++; continue; }
            if (array_key_exists($status, $counts)) $counts[$status]++;
        }

        return ApiResponse::success($counts);
    }

    public function live(): JsonResponse
    {
        if (!$this->can('canViewAttendance')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $company = Company::findOrFail($this->activeCompanyId());
        $tz = $company->timezone ?: 'UTC';
        $date = Carbon::now($tz);

        $buckets = ['checked_in' => [], 'not_checked_in' => [], 'currently_working' => [], 'checked_out' => [], 'late' => []];

        $employees = Employee::where('company_id', $company->id)->where('status', 'active')->get();
        $existing = Attendance::where('company_id', $company->id)
            ->whereDate('date', $date->toDateString())
            ->get()->keyBy('employee_id');

        foreach ($employees as $employee) {
            $row = $existing->get($employee->id);
            $entry = [
                'id' => $employee->id, 'name' => $employee->name,
                'employee_code' => $employee->employee_code, 'department' => $employee->department,
                'check_in' => $row->check_in ?? null, 'check_out' => $row->check_out ?? null,
                'status' => $row->status ?? null,
            ];

            if ($row && $row->check_in) {
                $buckets['checked_in'][] = $entry;
                $row->check_out ? $buckets['checked_out'][] = $entry : $buckets['currently_working'][] = $entry;
                if ($row->status === 'late') $buckets['late'][] = $entry;
            } else {
                $buckets['not_checked_in'][] = $entry;
            }
        }

        return ApiResponse::success($buckets);
    }

    public function syncNow(Request $request): JsonResponse
    {
        if (!$this->can('canUpdateAttendance')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $date = $request->filled('date') ? $request->date : now()->toDateString();
        Artisan::call('attendance:mark-absentees', ['--date' => $date, '--company' => $this->activeCompanyId()]);

        return ApiResponse::success(null, 'Attendance sync completed');
    }

    public function calendar(Request $request): JsonResponse
    {
        if (!$this->can('canViewAttendance')) {
            return ApiResponse::error('Permission denied', 403);
        }

        $companyId = $this->activeCompanyId();

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'month'       => ['required', 'integer', 'min:1', 'max:12'],
            'year'        => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $employee = Employee::where('company_id', $companyId)->findOrFail($validated['employee_id']);
        $company = Company::findOrFail($companyId);
        $calculator = new AttendanceCalculator($company);

        $start = Carbon::create($validated['year'], $validated['month'], 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $today = Carbon::now($company->timezone ?: 'UTC')->startOfDay();

        $rows = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()->keyBy(fn ($r) => $r->date->toDateString());

        $days = [];
        $stats = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'half_day' => 0, 'holiday' => 0, 'off_day' => 0];
        $workedMinutes = 0;
        $overtimeMinutes = 0;
        $lateMinutes = 0;

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $dateStr = $cursor->toDateString();
            $row = $rows->get($dateStr);

            if ($row) {
                $status = $row->status;
                $workedMinutes += $row->worked_minutes ?? 0;
                $overtimeMinutes += $row->overtime_minutes ?? 0;
                $lateMinutes += $row->late_by_minutes ?? 0;
            } elseif ($cursor->lte($today)) {
                $status = $calculator->compute($employee, $cursor->copy(), null, null, null)['status'];
            } else {
                $status = null;
            }

            if ($status && array_key_exists($status, $stats)) $stats[$status]++;

            $days[] = [
                'date' => $dateStr, 'status' => $status,
                'check_in' => $row->check_in ?? null, 'check_out' => $row->check_out ?? null,
                'worked_minutes' => $row->worked_minutes ?? null, 'late_by_minutes' => $row->late_by_minutes ?? null,
                'overtime_minutes' => $row->overtime_minutes ?? null,
            ];
        }

        $countedDays = $stats['present'] + $stats['late'] + $stats['half_day'] + $stats['absent'];
        $presentLike = $stats['present'] + $stats['late'] + $stats['half_day'];
        $attendancePercentage = $countedDays > 0 ? round(($presentLike / $countedDays) * 100, 1) : 0;

        return ApiResponse::success([
            'days'                   => $days,
            'stats'                  => $stats,
            'total_worked_minutes'   => $workedMinutes,
            'total_overtime_minutes' => $overtimeMinutes,
            'total_late_minutes'     => $lateMinutes,
            'attendance_percentage'  => $attendancePercentage,
        ]);
    }

    // ── Self check-in / check-out ─────────────────────────────────────────

    public function checkIn(): JsonResponse
    {
        $employee = $this->ownEmployee();
        if (!$employee) {
            return ApiResponse::error('No employee profile is linked to your account.', 422);
        }

        $company = Company::findOrFail($employee->company_id);
        $tz = $company->timezone ?: 'UTC';
        $now = Carbon::now($tz);

        $existing = Attendance::where('employee_id', $employee->id)->whereDate('date', $now->toDateString())->first();
        if ($existing && $existing->check_in) {
            return ApiResponse::error('You have already checked in today.', 422);
        }

        $calculator = new AttendanceCalculator($company);

        if ($calculator->blockingLeaveFor($employee, $now)) {
            return ApiResponse::error('You are on approved leave today and cannot check in.', 422);
        }

        $row = $calculator->compute($employee, $now, $now->format('H:i'), null, false);
        $row['source'] = 'self';
        $row['manual_time'] = false;

        $attendance = Attendance::updateOrCreate(
            ['employee_id' => $employee->id, 'date' => $now->toDateString()],
            $row
        );

        return ApiResponse::success($attendance, 'Checked in');
    }

    public function checkOut(): JsonResponse
    {
        $employee = $this->ownEmployee();
        if (!$employee) {
            return ApiResponse::error('No employee profile is linked to your account.', 422);
        }

        $company = Company::findOrFail($employee->company_id);
        $tz = $company->timezone ?: 'UTC';
        $now = Carbon::now($tz);

        $existing = Attendance::where('employee_id', $employee->id)->whereDate('date', $now->toDateString())->first();
        if (!$existing || !$existing->check_in) {
            return ApiResponse::error('You have not checked in today.', 422);
        }
        if ($existing->check_out) {
            return ApiResponse::error('You have already checked out today.', 422);
        }

        $calculator = new AttendanceCalculator($company);
        $row = $calculator->compute($employee, $now, $existing->check_in, $now->format('H:i'), false);
        $row['source'] = 'self';
        $row['manual_time'] = false;

        $existing->update($row);

        return ApiResponse::success($existing->fresh(), 'Checked out');
    }
}

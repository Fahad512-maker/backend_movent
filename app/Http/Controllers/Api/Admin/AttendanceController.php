<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Api\Admin\Concerns\ScopesToActiveCompany;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\SystemAuditLog;
use App\Services\AttendanceActionService;
use App\Services\AttendanceCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

class AttendanceController extends Controller
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

        $q = Attendance::whereIn('company_id', $companyIds)
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

    public function checkInEmployee(Request $request, int $employeeId): JsonResponse
    {
        $validated = $request->validate([
            'time_mode' => ['nullable', 'in:current,manual'],
            'time'      => ['nullable', 'date_format:H:i'],
            'date'      => ['nullable', 'date'],
            'reason'    => ['nullable', 'string', 'max:500'],
            'note'      => ['nullable', 'string', 'max:1000'],
        ]);

        $employee = Employee::whereIn('company_id', $this->activeCompanyIds())
            ->with('shift')
            ->findOrFail($employeeId);
        $company = Company::findOrFail($employee->company_id);

        $attendance = (new AttendanceActionService($company))
            ->checkIn($employee, $validated, 'admin:' . $this->admin()->id);

        return ApiResponse::success($attendance, 'Checked in');
    }

    public function checkOutEmployee(Request $request, int $employeeId): JsonResponse
    {
        $validated = $request->validate([
            'time_mode' => ['nullable', 'in:current,manual'],
            'time'      => ['nullable', 'date_format:H:i'],
            'reason'    => ['nullable', 'string', 'max:500'],
            'note'      => ['nullable', 'string', 'max:1000'],
        ]);

        $employee = Employee::whereIn('company_id', $this->activeCompanyIds())
            ->with('shift')
            ->findOrFail($employeeId);
        $company = Company::findOrFail($employee->company_id);

        $attendance = (new AttendanceActionService($company))
            ->checkOut($employee, $validated, 'admin:' . $this->admin()->id);

        return ApiResponse::success($attendance, 'Checked out');
    }

    // "Mark Attendance" — upserts one employee+date row. Always runs the
    // real calculation engine (populates shift/department snapshots, worked
    // hours, late-by, etc.) so a purely status-driven call from an old
    // caller still lands a fully-formed row; an explicit `status` here wins
    // over whatever the calculator would have derived (manual override).
    public function mark(Request $request): JsonResponse
    {
        $companyIds = $this->companyIds();

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'date'        => ['required', 'date'],
            // Optional now — omitting it lets the calculator's own status
            // win (the spec's core requirement: status is derived from
            // shift/check-in/check-out/grace, not just hand-picked). Only
            // set this to force an explicit override.
            'status'      => ['nullable', 'in:present,absent,late,half_day,holiday,leave,off_day'],
            'check_in'    => ['nullable', 'date_format:H:i'],
            'check_out'   => ['nullable', 'date_format:H:i'],
            'notes'       => ['nullable', 'string'],
        ]);

        $employee = Employee::whereIn('company_id', $companyIds)->findOrFail($validated['employee_id']);
        $company = Company::findOrFail($employee->company_id);
        $calculator = new AttendanceCalculator($company);

        $row = $calculator->compute($employee, Carbon::parse($validated['date']), $validated['check_in'] ?? null, $validated['check_out'] ?? null, true);
        $isOverride = !empty($validated['status']);
        if ($isOverride) $row['status'] = $validated['status'];
        // source='manual' either way — HR entered this row through this
        // endpoint; is_manual_override tracks specifically whether the
        // *status* was forced rather than left to the calculator.
        $row['source'] = 'manual';
        $row['is_manual_override'] = $isOverride;
        $row['notes'] = $validated['notes'] ?? null;

        $attendance = Attendance::updateOrCreate(
            ['employee_id' => $employee->id, 'date' => $validated['date']],
            $row
        );

        SystemAuditLog::create([
            'company_id'  => $employee->company_id,
            'user_id'     => null,
            'action'      => 'attendance_marked',
            'module_key'  => 'hr',
            'entity_type' => 'Employee',
            'entity_id'   => $employee->id,
            'new_values'  => $validated,
        ]);

        return ApiResponse::success($attendance->load('employee:id,name,employee_code'), 'Attendance marked');
    }

    // Correction — requires a reason, snapshots the pre-change row into
    // SystemAuditLog::old_values so it shows up in history().
    public function correct(Request $request, int $id): JsonResponse
    {
        $companyIds = $this->companyIds();
        $attendance = Attendance::whereIn('company_id', $companyIds)->findOrFail($id);

        $validated = $request->validate([
            'status'    => ['nullable', 'in:present,absent,late,half_day,holiday,leave,off_day'],
            'check_in'  => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'notes'     => ['nullable', 'string'],
            'reason'    => ['required', 'string', 'max:500'],
        ]);

        $old = $attendance->only(['status', 'check_in', 'check_out', 'worked_minutes', 'late_by_minutes', 'overtime_minutes', 'notes']);

        $employee = Employee::whereIn('company_id', $companyIds)->findOrFail($attendance->employee_id);
        $company = Company::findOrFail($employee->company_id);
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
            'user_id'     => null,
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
        $companyIds = $this->companyIds();
        Attendance::whereIn('company_id', $companyIds)->findOrFail($id);

        $logs = SystemAuditLog::where('entity_type', 'Attendance')
            ->where('entity_id', $id)
            ->where('action', 'attendance_corrected')
            ->orderByDesc('created_at')
            ->get(['id', 'user_id', 'old_values', 'new_values', 'created_at']);

        return ApiResponse::success($logs);
    }

    // Marks multiple employees for the same date in one request — a daily register.
    public function bulkMark(Request $request): JsonResponse
    {
        $companyIds = $this->companyIds();

        $validated = $request->validate([
            'date'                    => ['required', 'date'],
            'entries'                 => ['required', 'array'],
            'entries.*.employee_id'   => ['required', 'integer'],
            'entries.*.status'        => ['required', 'in:present,absent,late,half_day,holiday,leave,off_day'],
        ]);

        $employees = Employee::whereIn('company_id', $companyIds)
            ->whereIn('id', collect($validated['entries'])->pluck('employee_id'))
            ->get()
            ->keyBy('id');

        $results = [];
        foreach ($validated['entries'] as $entry) {
            if (!$employees->has($entry['employee_id'])) continue;
            $employee = $employees->get($entry['employee_id']);
            $company = Company::find($employee->company_id);
            $calculator = new AttendanceCalculator($company);
            $row = $calculator->compute($employee, Carbon::parse($validated['date']), null, null, true);
            $row['status'] = $entry['status'];
            $row['source'] = 'manual';
            $row['is_manual_override'] = true;
            $results[] = Attendance::updateOrCreate(
                ['employee_id' => $entry['employee_id'], 'date' => $validated['date']],
                $row
            );
        }

        foreach ($employees->pluck('company_id')->unique() as $companyId) {
            SystemAuditLog::create([
                'company_id'  => $companyId,
                'user_id'     => null,
                'action'      => 'attendance_bulk_marked',
                'module_key'  => 'hr',
                'entity_type' => 'Attendance',
                'entity_id'   => null,
                'new_values'  => ['date' => $validated['date'], 'count' => count($results)],
            ]);
        }

        return ApiResponse::success($results, count($results) . ' attendance records marked');
    }

    // Today's (or ?date=) breakdown counts — blends persisted rows with an
    // on-the-fly calculator pass for employees who don't have one yet, so
    // "not checked in" is accurate even before mark-absentees has finalized
    // anything for the day.
    public function summary(Request $request): JsonResponse
    {
        $companies = Company::whereIn('id', $this->activeCompanyIds())->get();

        $counts = [
            'total_employees' => 0, 'present' => 0, 'late' => 0, 'absent' => 0,
            'leave' => 0, 'half_day' => 0, 'holiday' => 0, 'off_day' => 0, 'not_checked_in' => 0,
            'currently_working' => 0, 'checked_out' => 0, 'off_today' => 0,
        ];

        foreach ($companies as $company) {
            $tz = $company->timezone ?: 'UTC';
            $date = $request->filled('date') ? Carbon::parse($request->date, $tz) : Carbon::now($tz);
            $calculator = new AttendanceCalculator($company);

            $employees = Employee::where('company_id', $company->id)->where('status', 'active')->get();
            $counts['total_employees'] += $employees->count();

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
        }

        return ApiResponse::success($counts);
    }

    // Live view for "today" — never invents Absent for an employee whose
    // shift hasn't ended yet (that's the whole point of the isFinal=null
    // auto-resolve in the calculator).
    public function live(Request $request): JsonResponse
    {
        $companies = Company::whereIn('id', $this->activeCompanyIds())->get();
        $buckets = ['checked_in' => [], 'not_checked_in' => [], 'currently_working' => [], 'checked_out' => [], 'late' => []];

        foreach ($companies as $company) {
            $tz = $company->timezone ?: 'UTC';
            $date = Carbon::now($tz);

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
        }

        return ApiResponse::success($buckets);
    }

    // For local/no-cron environments — runs the identical finalization
    // logic as the scheduled `attendance:mark-absentees` command, synchronously.
    public function syncNow(Request $request): JsonResponse
    {
        $date = $request->filled('date') ? $request->date : now()->toDateString();

        foreach ($this->activeCompanyIds() as $companyId) {
            Artisan::call('attendance:mark-absentees', ['--date' => $date, '--company' => $companyId]);
        }

        return ApiResponse::success(null, 'Attendance sync completed');
    }

    public function calendar(Request $request): JsonResponse
    {
        $companyIds = $this->companyIds();

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'month'       => ['required', 'integer', 'min:1', 'max:12'],
            'year'        => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $employee = Employee::whereIn('company_id', $companyIds)->findOrFail($validated['employee_id']);
        $company = Company::findOrFail($employee->company_id);
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
                $status = null; // future date
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
}

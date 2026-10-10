<?php

namespace App\Services;

use App\Models\AttendancePolicy;
use App\Models\Company;
use App\Models\CompanyHoliday;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Shift;
use Illuminate\Support\Carbon;

// Computes an attendance row's status + derived fields (late/early-leave/
// worked/overtime minutes) from an employee's assigned Shift, the company's
// AttendancePolicy, approved Leave, and CompanyHoliday — the calculation
// engine the Attendance module was missing entirely before (previously a
// pure manually-typed status dropdown, no real time-based logic at all).
class AttendanceCalculator
{
    public function __construct(private Company $company)
    {
    }

    public function policy(): AttendancePolicy
    {
        return AttendancePolicy::firstOrCreate(['company_id' => $this->company->id]);
    }

    public function resolveShift(Employee $employee): ?Shift
    {
        return $employee->shift_id ? Shift::find($employee->shift_id) : null;
    }

    private function workingDaysFor(?Shift $shift, AttendancePolicy $policy): array
    {
        return $shift?->working_days
            ?? $policy->default_working_days
            ?? AttendancePolicy::defaultWorkingDays();
    }

    private function combine(Carbon $date, string $time): Carbon
    {
        return Carbon::parse($date->toDateString() . ' ' . $time, $this->company->timezone ?: 'UTC');
    }

    // A day only becomes "final" (eligible to auto-resolve to Absent) once
    // its shift has actually ended — an employee whose shift starts at 6pm
    // must never show Absent at noon. Past dates are always final. An
    // employee with no shift assigned is never auto-finalized for "today" —
    // there's no schedule to know the day ended, so that case is left to
    // manual HR review rather than risking a false Absent.
    public function isDayFinal(Employee $employee, Carbon $date): bool
    {
        $today = Carbon::now($this->company->timezone ?: 'UTC')->startOfDay();
        $target = $date->copy()->startOfDay();

        if ($target->lt($today)) return true;
        if ($target->gt($today)) return false;

        $shift = $this->resolveShift($employee);
        if (!$shift) return false;

        $end = $this->combine($date, $shift->end_time);
        $start = $this->combine($date, $shift->start_time);
        if ($end->lessThanOrEqualTo($start)) $end->addDay(); // overnight shift
        $end->addMinutes($shift->grace_period_minutes);

        return Carbon::now($this->company->timezone ?: 'UTC')->greaterThanOrEqualTo($end);
    }

    private function findApprovedLeave(Employee $employee, Carbon $date): ?LeaveRequest
    {
        return LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $date->toDateString())
            ->whereDate('to_date', '>=', $date->toDateString())
            ->first();
    }

    // A full-day approved leave means the employee isn't expected in at all —
    // check-in must be refused outright rather than merely display as
    // "Leave" with a logged time underneath (which is what compute() still
    // does for anyone checked in by a path that doesn't call this, e.g. a
    // correction made before the leave was approved). A half-day leave does
    // NOT block: the employee is expected for the other half, so check-in
    // must still work for it — only is_half_day distinguishes the two here.
    public function blockingLeaveFor(Employee $employee, Carbon $date): ?LeaveRequest
    {
        $leave = $this->findApprovedLeave($employee, $date);

        return ($leave && !$leave->is_half_day) ? $leave : null;
    }

    private function findHoliday(Employee $employee, Carbon $date): ?CompanyHoliday
    {
        return CompanyHoliday::where('company_id', $employee->company_id)
            ->where(function ($q) use ($date) {
                $q->whereDate('date', $date->toDateString())
                    ->orWhere(function ($q2) use ($date) {
                        $q2->where('is_recurring_annual', true)
                            ->whereMonth('date', $date->month)
                            ->whereDay('date', $date->day);
                    });
            })
            ->first();
    }

    // $isFinal: null = auto-resolve via isDayFinal(); pass true/false to
    // force it (the mark-absentees command and sync-now endpoint always
    // pass true — they exist specifically to finalize).
    public function compute(Employee $employee, Carbon $date, ?string $checkIn = null, ?string $checkOut = null, ?bool $isFinal = null): array
    {
        $policy = $this->policy();
        $shift = $this->resolveShift($employee);
        $workingDays = $this->workingDaysFor($shift, $policy);
        $graceMinutes = $shift->grace_period_minutes ?? $policy->default_grace_period_minutes;
        $breakMinutes = $shift->break_minutes ?? 0;

        $row = [
            'employee_id'            => $employee->id,
            'company_id'             => $employee->company_id,
            'date'                   => $date->toDateString(),
            'department'             => $employee->department,
            'shift_id'               => $shift?->id,
            'shift_name'             => $shift?->name,
            'scheduled_check_in'     => $shift?->start_time,
            'scheduled_check_out'    => $shift?->end_time,
            'grace_period_minutes'   => $graceMinutes,
            'break_minutes'          => $breakMinutes,
            'check_in'               => $checkIn,
            'check_out'              => $checkOut,
            'late_by_minutes'        => null,
            'early_leave_by_minutes' => null,
            'gross_minutes'          => null,
            'worked_minutes'         => null,
            'overtime_minutes'       => null,
            'is_missing_checkout'    => false,
            'calculated_at'          => now(),
        ];

        // Precedence: Leave > Holiday > Off Day — these set the *displayed*
        // status, but if a check-in actually exists (worked on a leave/
        // holiday/off day), the worked-hours math below still runs so
        // payroll can see real time worked, it just doesn't change status.
        $forcedStatus = null;

        $leave = $this->findApprovedLeave($employee, $date);
        if ($leave) {
            $forcedStatus = $leave->is_half_day ? 'half_day' : 'leave';
        }

        if (!$forcedStatus) {
            $holiday = $this->findHoliday($employee, $date);
            if ($holiday) $forcedStatus = 'holiday';
        }

        if (!$forcedStatus && !in_array($date->isoWeekday(), $workingDays, true)) {
            $forcedStatus = 'off_day';
        }

        if (!$checkIn) {
            if ($forcedStatus) {
                $row['status'] = $forcedStatus;
            } else {
                $isFinal ??= $this->isDayFinal($employee, $date);
                $row['status'] = $isFinal ? 'absent' : 'pending';
            }

            return $row;
        }

        // Check-in exists — always compute the real time-based metrics,
        // even when a forced status (leave/holiday/off-day) will be shown.
        $checkInDt = $this->combine($date, $checkIn);

        $lateBy = 0;
        if ($shift) {
            $scheduledStart = $this->combine($date, $shift->start_time);
            $graceEnd = $scheduledStart->copy()->addMinutes($graceMinutes);
            // Grace period only gates whether it counts as "late" at all;
            // once past grace, the displayed lateness is measured from the
            // actual scheduled start (e.g. 09:00 start, 15min grace, 09:22
            // check-in -> Late By 22 min, not 7 min from the grace cutoff).
            if ($checkInDt->greaterThan($graceEnd)) {
                $lateBy = $scheduledStart->diffInMinutes($checkInDt);
            }
        }
        $row['late_by_minutes'] = $lateBy;
        $computedStatus = $lateBy > 0 ? 'late' : 'present';

        if ($checkOut) {
            $checkOutDt = $this->combine($date, $checkOut);
            // Strictly-less-than only — an equal check-in/check-out instant
            // (e.g. a same-minute self check-in immediately followed by
            // check-out) must resolve to 0 worked minutes, not a spurious
            // 24h wraparound.
            if ($checkOutDt->lessThan($checkInDt)) $checkOutDt->addDay(); // overnight

            // Gross = raw check-out minus check-in (total time on-site).
            // Worked (net) = gross minus break — the two are shown side by
            // side so HR can see both "how long were they here" and "how
            // long did they actually work."
            $grossMinutes = $checkInDt->diffInMinutes($checkOutDt);
            $row['gross_minutes'] = $grossMinutes;

            $workedMinutes = max(0, $grossMinutes - $breakMinutes);
            $row['worked_minutes'] = $workedMinutes;

            if ($shift) {
                $scheduledEnd = $this->combine($date, $shift->end_time);
                $scheduledStart = $this->combine($date, $shift->start_time);
                if ($scheduledEnd->lessThanOrEqualTo($scheduledStart)) $scheduledEnd->addDay(); // overnight

                $row['early_leave_by_minutes'] = $checkOutDt->lessThan($scheduledEnd)
                    ? $checkOutDt->diffInMinutes($scheduledEnd)
                    : 0;

                if ($policy->overtime_enabled) {
                    $pastEnd = $checkOutDt->greaterThan($scheduledEnd) ? $scheduledEnd->diffInMinutes($checkOutDt) : 0;
                    $minThreshold = $policy->overtime_threshold_minutes ?? 0;
                    $row['overtime_minutes'] = $pastEnd >= $minThreshold ? $pastEnd : 0;
                }
            }

            // Half-Day override: below full-day but at/above half-day
            // threshold wins over the punctuality-based present/late call —
            // the day is complete and simply too short.
            if ($workedMinutes < $policy->full_day_threshold_minutes && $workedMinutes >= $policy->half_day_threshold_minutes) {
                $computedStatus = 'half_day';
            }
        } else {
            $row['is_missing_checkout'] = true;
        }

        $row['status'] = $forcedStatus ?? $computedStatus;

        return $row;
    }
}

<?php

namespace App\Services;

use App\Models\AttendancePolicy;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Shift;
use Illuminate\Support\Carbon;

// Computes salary deduction for one employee's payroll month from that
// month's Attendance records — Late/Half Day/Absent/Off Day counts, each
// billed as (count beyond the company's free allowance) x daily rate x
// that status's configured percentage. Daily rate = monthly salary /
// working days in that month (per the employee's shift, falling back to
// the company's AttendancePolicy default).
class AttendanceDeductionCalculator
{
    public function __construct(private Company $company)
    {
    }

    private function policy(): AttendancePolicy
    {
        return AttendancePolicy::firstOrCreate(['company_id' => $this->company->id]);
    }

    private function workingDaysInMonth(Employee $employee, Carbon $start, Carbon $end): int
    {
        $policy = $this->policy();
        $shift = $employee->shift_id ? Shift::find($employee->shift_id) : null;
        $workingDays = $shift?->working_days ?? $policy->default_working_days ?? AttendancePolicy::defaultWorkingDays();

        $count = 0;
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if (in_array($d->isoWeekday(), $workingDays, true)) $count++;
        }

        return $count;
    }

    // $monthYear = "YYYY-MM". Returns a breakdown + total deduction amount;
    // never persists anything — the caller (PayrollController) decides
    // whether/when to store the result.
    public function compute(Employee $employee, string $monthYear, float $monthlySalary): array
    {
        $policy = $this->policy();
        $start = Carbon::createFromFormat('Y-m-d', $monthYear . '-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $workingDays = $this->workingDaysInMonth($employee, $start, $end);
        $dailyRate = $workingDays > 0 ? $monthlySalary / $workingDays : 0;

        $counts = ['late' => 0, 'half_day' => 0, 'absent' => 0, 'off_day' => 0];
        Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get(['status'])
            ->each(function ($row) use (&$counts) {
                if (array_key_exists($row->status, $counts)) $counts[$row->status]++;
            });

        $rules = [
            'late'     => [(float) $policy->late_deduction_percent, $policy->free_late_allowed],
            'half_day' => [(float) $policy->half_day_deduction_percent, $policy->free_half_day_allowed],
            'absent'   => [(float) $policy->absent_deduction_percent, $policy->free_absent_allowed],
            'off_day'  => [(float) $policy->off_day_deduction_percent, $policy->free_off_day_allowed],
        ];

        $breakdown = [];
        $total = 0;
        foreach ($rules as $key => [$percent, $freeAllowed]) {
            $count = $counts[$key];
            $billable = max(0, $count - $freeAllowed);
            $amount = round($billable * $dailyRate * ($percent / 100), 2);

            $breakdown[$key] = [
                'count'          => $count,
                'free_allowed'   => $freeAllowed,
                'billable_count' => $billable,
                'percent'        => $percent,
                'amount'         => $amount,
            ];
            $total += $amount;
        }

        return [
            'daily_rate'             => round($dailyRate, 2),
            'working_days_in_month'  => $workingDays,
            'breakdown'              => $breakdown,
            'total'                  => round($total, 2),
        ];
    }
}

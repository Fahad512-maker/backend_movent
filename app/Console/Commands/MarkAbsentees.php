<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Services\AttendanceCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

// Finalizes Absent status for employees whose shift ended today with no
// check-in. Runs hourly (see routes/console.php) rather than once-daily
// because each company's shift-end passes at a different company-local
// time; idempotent (skips employees who already have a check-in or an
// already-finalized row) so repeated/overlapping runs are safe.
//
// Only ever persists 'absent' rows here — Leave/Holiday/Off-Day are cheap
// to re-derive on demand (LeaveRequest / CompanyHoliday / working_days
// lookups) from AttendanceController::index()/summary()/live(), so this
// command never bloats the `attendance` table with a row per employee for
// every weekend/holiday that ever passes.
class MarkAbsentees extends Command
{
    protected $signature = 'attendance:mark-absentees {--date=} {--company=}';

    protected $description = 'Auto-marks employees Absent once their shift for the day has ended with no check-in';

    public function handle(): int
    {
        // operational(): skips a company a Super Admin deactivated or its own
        // Company Admin manually suspended — no new attendance records get
        // written for it until it's reactivated, same as every other
        // per-company scheduled/business action (see Company::isOperational()).
        $companies = Company::operational()->when($this->option('company'), fn ($q, $id) => $q->where('id', $id))->get();
        $marked = 0;

        foreach ($companies as $company) {
            $tz = $company->timezone ?: 'UTC';
            $date = $this->option('date')
                ? Carbon::parse($this->option('date'), $tz)
                : Carbon::now($tz);

            $calculator = new AttendanceCalculator($company);

            Employee::where('company_id', $company->id)
                ->where('status', 'active')
                ->each(function (Employee $employee) use ($calculator, $date, &$marked) {
                    try {
                        $existing = Attendance::where('employee_id', $employee->id)
                            ->whereDate('date', $date->toDateString())
                            ->first();

                        // Already has a check-in, or was already finalized —
                        // nothing to do (idempotent).
                        if ($existing && ($existing->check_in || $existing->status === 'absent')) {
                            return;
                        }

                        if (!$calculator->isDayFinal($employee, $date)) return;

                        $row = $calculator->compute($employee, $date, null, null, true);
                        if ($row['status'] !== 'absent') return; // Leave/Holiday/Off-Day — nothing to lock in

                        $row['is_manual_override'] = false;
                        Attendance::updateOrCreate(
                            ['employee_id' => $employee->id, 'date' => $date->toDateString()],
                            $row
                        );
                        $marked++;
                    } catch (\Throwable $e) {
                        Log::warning("attendance:mark-absentees failed for employee {$employee->id}: {$e->getMessage()}");
                    }
                });
        }

        $this->info("Marked {$marked} employee(s) absent.");

        return self::SUCCESS;
    }
}

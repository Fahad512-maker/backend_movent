<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\SystemAuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AttendanceActionService
{
    public function __construct(private Company $company)
    {
    }

    public function checkIn(Employee $employee, array $payload, ?string $actor = null): Attendance
    {
        $shift = $employee->shift;
        if (!$shift) {
            throw ValidationException::withMessages([
                'employee_id' => 'No shift is assigned to this employee.',
            ]);
        }

        [$date, $time, $manual] = $this->resolveDateTime($payload, true);

        $existing = Attendance::where('employee_id', $employee->id)
            ->whereDate('date', $date->toDateString())
            ->first();

        if ($existing && $existing->check_in) {
            throw ValidationException::withMessages([
                'employee_id' => 'This employee has already checked in for this attendance session.',
            ]);
        }

        $calculator = new AttendanceCalculator($this->company);

        if ($calculator->blockingLeaveFor($employee, $date)) {
            throw ValidationException::withMessages([
                'employee_id' => 'This employee is on approved leave today and cannot be checked in.',
            ]);
        }

        $row = $calculator->compute($employee, $date, $time, null, false);
        $row['source'] = $manual ? 'manual' : 'system';
        $row['is_manual_override'] = false;
        $row['manual_time'] = $manual;
        $row['manual_entered_by'] = $manual ? $actor : null;
        $row['manual_reason'] = $manual ? ($payload['reason'] ?? null) : null;
        $row['manual_created_at'] = $manual ? now() : null;
        $row['notes'] = $payload['note'] ?? $payload['reason'] ?? null;

        $attendance = Attendance::updateOrCreate(
            ['employee_id' => $employee->id, 'date' => $date->toDateString()],
            $row
        );

        $this->audit('attendance_checked_in', $attendance, $actor, [
            'check_in' => $time,
            'manual_time' => $manual,
            'reason' => $payload['reason'] ?? null,
        ]);

        return $attendance->fresh()->load('employee:id,name,employee_code,department');
    }

    public function checkOut(Employee $employee, array $payload, ?string $actor = null): Attendance
    {
        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->orderByDesc('date')
            ->first();

        if (!$attendance) {
            throw ValidationException::withMessages([
                'employee_id' => 'This employee has no open attendance session to check out.',
            ]);
        }

        [$date, $time, $manual] = $this->resolveDateTime($payload, false, Carbon::parse($attendance->date, $this->timezone()));

        $calculator = new AttendanceCalculator($this->company);
        $row = $calculator->compute($employee, $date, $attendance->check_in, $time, false);
        $row['source'] = $manual ? 'manual' : ($attendance->source ?: 'system');
        $row['is_manual_override'] = false;
        $row['manual_time'] = (bool) ($attendance->manual_time || $manual);
        $row['manual_entered_by'] = $manual ? $actor : $attendance->manual_entered_by;
        $row['manual_reason'] = $manual ? ($payload['reason'] ?? null) : $attendance->manual_reason;
        $row['manual_created_at'] = $manual ? now() : $attendance->manual_created_at;
        $row['notes'] = $payload['note'] ?? $attendance->notes;

        $attendance->update($row);

        $this->audit('attendance_checked_out', $attendance->fresh(), $actor, [
            'check_out' => $time,
            'manual_time' => $manual,
            'reason' => $payload['reason'] ?? null,
        ]);

        return $attendance->fresh()->load('employee:id,name,employee_code,department');
    }

    private function resolveDateTime(array $payload, bool $allowManualDate, ?Carbon $defaultDate = null): array
    {
        $tz = $this->timezone();
        $mode = $payload['time_mode'] ?? 'current';
        $manual = $mode === 'manual';

        if (!$manual) {
            $now = Carbon::now($tz);
            return [$defaultDate ?: $now, $now->format('H:i'), false];
        }

        if (empty($payload['time'])) {
            throw ValidationException::withMessages(['time' => 'Manual time is required.']);
        }

        if (empty($payload['reason'])) {
            throw ValidationException::withMessages(['reason' => 'A reason is required when manual time is used.']);
        }

        $date = $defaultDate ?: Carbon::now($tz);
        if ($allowManualDate && !empty($payload['date'])) {
            $date = Carbon::parse($payload['date'], $tz);
        }

        return [$date, $payload['time'], true];
    }

    private function audit(string $action, Attendance $attendance, ?string $actor, array $values): void
    {
        SystemAuditLog::create([
            'company_id' => $attendance->company_id,
            'user_id' => null,
            'action' => $action,
            'module_key' => 'hr',
            'entity_type' => 'Attendance',
            'entity_id' => $attendance->id,
            'new_values' => array_merge($values, ['actor' => $actor]),
        ]);
    }

    private function timezone(): string
    {
        return $this->company->timezone ?: 'UTC';
    }
}

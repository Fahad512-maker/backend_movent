<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $table = 'attendance';

    protected $fillable = [
        'employee_id', 'company_id', 'date', 'department',
        'shift_id', 'shift_name', 'scheduled_check_in', 'scheduled_check_out',
        'grace_period_minutes', 'break_minutes',
        'check_in', 'check_out', 'status',
        'late_by_minutes', 'early_leave_by_minutes', 'gross_minutes', 'worked_minutes', 'overtime_minutes',
        'is_missing_checkout', 'source', 'is_manual_override',
        'manual_time', 'manual_entered_by', 'manual_reason', 'manual_created_at',
        'calculated_at',
        'notes',
    ];

    protected $casts = [
        'date'                 => 'date',
        'is_missing_checkout'  => 'boolean',
        'is_manual_override'   => 'boolean',
        'manual_time'          => 'boolean',
        'manual_created_at'    => 'datetime',
        'calculated_at'        => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payroll extends Model
{
    protected $table = 'payroll';

    protected $fillable = [
        'employee_id', 'month_year', 'basic_salary', 'allowances', 'deductions',
        // Auto-calculated from that month's Attendance (Late/Half Day/Absent
        // beyond the company's free allowance) — separate from `deductions`
        // (manual: loans, penalties, etc.) so it stays visible/auditable on
        // its own. Still editable pre-process, same as `deductions`.
        'attendance_deduction', 'attendance_deduction_breakdown',
        'net_pay', 'status', 'processed_by', 'paid_at',
    ];

    protected $casts = [
        'basic_salary'                   => 'decimal:2',
        'allowances'                     => 'decimal:2',
        'deductions'                     => 'decimal:2',
        'attendance_deduction'           => 'decimal:2',
        'attendance_deduction_breakdown' => 'array',
        'net_pay'                        => 'decimal:2',
        'paid_at'                        => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    protected $fillable = [
        'employee_id', 'leave_type', 'from_date', 'to_date', 'total_days',
        'is_half_day', 'half_day_period',
        'reason', 'status', 'approved_by',
    ];

    protected $casts = [
        'from_date'    => 'date',
        'to_date'      => 'date',
        'is_half_day'  => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

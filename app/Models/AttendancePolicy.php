<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePolicy extends Model
{
    protected $fillable = [
        'company_id', 'half_day_threshold_minutes', 'full_day_threshold_minutes',
        'overtime_enabled', 'overtime_threshold_minutes',
        'default_grace_period_minutes', 'default_working_days',
        'late_deduction_percent', 'half_day_deduction_percent',
        'absent_deduction_percent', 'off_day_deduction_percent',
        'free_late_allowed', 'free_half_day_allowed',
        'free_absent_allowed', 'free_off_day_allowed',
    ];

    protected $casts = [
        'overtime_enabled'      => 'boolean',
        'default_working_days'  => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // App-level fallback when a company hasn't configured this yet —
    // callers should use App\Services\AttendanceCalculator::policyFor()
    // rather than constructing this directly, but the default lives here
    // so it's defined in exactly one place.
    public static function defaultWorkingDays(): array
    {
        return [1, 2, 3, 4, 5];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Single-row (id=1) global Trial/Suspension/Subscription policy — see the
// subscription_policies migration for what each column controls. Every
// consumer (CheckSubscriptionExpiry command, EnsureSubscriptionActive
// middleware, SubscriptionPaymentController, PublicController registration)
// must read through current() rather than querying the table directly, so
// there is exactly one place that decides what "no row yet" defaults to.
class SubscriptionPolicy extends Model
{
    protected $fillable = [
        'trial_enabled', 'trial_duration_days', 'trial_modules',
        'grace_period_days', 'renewal_reminder_days',
        'auto_suspend_on_trial_expiry', 'auto_suspend_on_renewal_failure',
        'trial_expiry_behavior', 'renewal_failure_behavior',
        'payment_policy_notes', 'updated_by_super_admin_id',
    ];

    protected $casts = [
        'trial_enabled' => 'boolean',
        'trial_duration_days' => 'integer',
        'trial_modules' => 'array',
        'grace_period_days' => 'integer',
        'renewal_reminder_days' => 'array',
        'auto_suspend_on_trial_expiry' => 'boolean',
        'auto_suspend_on_renewal_failure' => 'boolean',
    ];

    public function updatedBySuperAdmin(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'updated_by_super_admin_id');
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyAssignment extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'policy_id', 'user_id', 'assigned_by', 'is_acknowledged', 'acknowledged_at', 'due_date',
    ];

    protected $casts = [
        'is_acknowledged' => 'boolean',
        'acknowledged_at' => 'datetime',
        'due_date'        => 'date',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(CompliancePolicy::class, 'policy_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}

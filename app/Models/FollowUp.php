<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUp extends Model
{
    protected $fillable = [
        'lead_id', 'company_id', 'created_by', 'assigned_to',
        'type', 'scheduled_at', 'completed_at', 'notes',
        'status', 'reminder_enabled',
    ];

    protected $casts = [
        'scheduled_at'    => 'datetime',
        'completed_at'    => 'datetime',
        'reminder_enabled' => 'boolean',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

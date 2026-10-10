<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceItem extends Model
{
    protected $fillable = [
        'company_id', 'project_id', 'policy_id', 'assigned_to', 'created_by',
        'title', 'description', 'category', 'status', 'due_date',
        'completed_at', 'evidence_path', 'notes',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'completed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(CompliancePolicy::class, 'policy_id');
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

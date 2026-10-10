<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceIncident extends Model
{
    protected $fillable = [
        'company_id', 'project_id', 'reported_by', 'assigned_to', 'title',
        'description', 'category', 'severity', 'status', 'occurred_at',
        'resolution_notes', 'evidence_path', 'resolved_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function violations(): HasMany
    {
        return $this->hasMany(ComplianceViolation::class, 'incident_id');
    }
}

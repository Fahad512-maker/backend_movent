<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceViolation extends Model
{
    protected $fillable = [
        'company_id', 'project_id', 'policy_id', 'incident_id', 'reported_by',
        'violator_user_id', 'resolved_by', 'title', 'description', 'severity',
        'status', 'action_taken', 'resolved_at',
    ];

    protected $casts = ['resolved_at' => 'datetime'];

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

    public function incident(): BelongsTo
    {
        return $this->belongsTo(ComplianceIncident::class, 'incident_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function violator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'violator_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}

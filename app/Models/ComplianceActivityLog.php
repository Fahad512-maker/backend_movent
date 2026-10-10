<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Append-only, no updated_at.
class ComplianceActivityLog extends Model
{
    const UPDATED_AT = null;

    // Eloquent's default pluralization would guess "compliance_activity_logs"
    // ("log" -> "logs") — the migration created the table as the singular
    // "compliance_activity_log", so this must be set explicitly.
    protected $table = 'compliance_activity_log';

    protected $fillable = [
        'company_id', 'project_id', 'client_id', 'compliance_case_id',
        'actor_admin_id', 'actor_user_id', 'action', 'entity_type', 'entity_id',
        'old_value', 'new_value', 'description',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class, 'compliance_case_id');
    }

    public function actorAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'actor_admin_id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

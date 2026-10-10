<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Stub model — filled in during Phase 2. Internal-only, no client-facing
// read path — see the migration comment for why there's no visibility flag.
class ComplianceComment extends Model
{
    protected $fillable = [
        'company_id', 'project_id', 'compliance_case_id', 'body',
        'author_admin_id', 'author_user_id',
    ];

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class, 'compliance_case_id');
    }

    public function authorAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'author_admin_id');
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}

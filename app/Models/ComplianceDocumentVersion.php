<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Stub model — filled in during Phase 2.
class ComplianceDocumentVersion extends Model
{
    protected $fillable = [
        'company_id', 'compliance_document_id', 'version_number',
        'file_name', 'file_path', 'file_type', 'file_size',
        'uploaded_by_admin_id', 'uploaded_by_user_id', 'is_current',
        'status', 'review_notes',
    ];

    protected $casts = [
        'is_current' => 'boolean',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(ComplianceDocument::class, 'compliance_document_id');
    }

    public function uploadedByAdmin(): BelongsTo
    {
        return $this->belongsTo(CompanyAdmin::class, 'uploaded_by_admin_id');
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}

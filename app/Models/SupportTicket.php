<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\PublicFile;

class SupportTicket extends Model
{
    // Single source of truth for the three controllers (Admin/User/Client)
    // that each validate these fields — keeps the three from drifting apart.
    const CATEGORIES = [
        'project_support', 'billing_payments', 'technical_issue', 'general_inquiry',
        'account_login', 'file_document_request', 'revision_request', 'bug_report',
        'feature_request', 'other',
    ];
    const PRIORITIES = ['low', 'medium', 'high', 'urgent'];
    const STATUSES = ['open', 'in_progress', 'on_hold', 'resolved', 'closed'];

    const PROJECT_CATEGORY = 'project_support';
    const BILLING_CATEGORY = 'billing_payments';

    // Statuses a project must be in for a new Project Support ticket to
    // auto-reopen it — 'approved_locked' is deliberately excluded since that
    // is the client's own final sign-off/billing gate, not just a paused
    // state (see Api\Client\SupportController::store()).
    const AUTO_REOPEN_PROJECT_STATUSES = ['closed', 'completed'];

    protected $fillable = [
        'company_id', 'raised_by', 'assigned_to', 'subject', 'category', 'description',
        'status', 'priority', 'attachment_path', 'attachment_name', 'resolved_at',
        'project_id', 'invoice_id', 'payment_reference',
        // 2026_09_22_100100_add_storage_type_to_attachment_tables — added to
        // the migration but not here until now, so every ::create() silently
        // dropped them (no preventSilentlyDiscardingAttributes() in this
        // app). See App\Services\AttachmentStorageResult::locationAttributes().
        'attachment_size', 'storage_type', 'google_drive_file_id', 'google_drive_folder_id',
    ];

    protected $casts = ['resolved_at' => 'datetime'];

    protected $appends = ['attachment_url'];

    // Unchanged for an 'internal' row — a Drive-backed one (no local
    // attachment_path) has no direct public URL; download it through the
    // authenticated GET .../support/{id}/attachment route instead (see
    // Api\{Admin,User,Client}\SupportController::downloadAttachment()).
    public function getAttachmentUrlAttribute(): ?string
    {
        return PublicFile::url($this->attachment_path);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SupportTicketReply::class, 'ticket_id');
    }
}
